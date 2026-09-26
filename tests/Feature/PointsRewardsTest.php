<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\Notification;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use App\Services\PointsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PointsRewardsTest extends TestCase
{
    // ========================================
    // ISOLATED DATABASE AND TRUSTED FIXTURES
    // All inventory is test-only. No live rewards or pushes are created.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    private function signIn(): User
    {
        $this->app['auth']->forgetGuards();
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function participation(User $user, string $status = 'for_verification'): DonationParticipation
    {
        $opportunity = DonationOpportunity::create(['title' => 'Donation', 'description' => 'Test event', 'location' => 'Test venue',
            'event_date' => now()->toDateString(), 'points_reward' => 9999, 'status' => 'published']);
        $row = new DonationParticipation;
        $row->forceFill(['user_id' => $user->id, 'donation_opportunity_id' => $opportunity->id, 'status' => $status, 'joined_at' => now()])->save();

        return $row;
    }

    private function complete(User $user): DonationParticipation
    {
        $row = $this->participation($user);
        $row->forceFill(['status' => 'completed', 'verified_at' => now()])->save();

        return $row;
    }

    private function buy(Reward $reward, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/rewards/'.$reward->id.'/redeem', ['request_key' => $key ?? (string) Str::uuid()]);
    }

    // ========================================
    // AUTHENTICATION AND QUIET STATES
    // Donors cannot award themselves points or choose a price/status.
    // ========================================
    public static function routes(): array
    {
        return [['get', '/api/points/summary'], ['get', '/api/points/transactions'], ['get', '/api/rewards'],
            ['get', '/api/rewards/1'], ['post', '/api/rewards/1/redeem'], ['get', '/api/vouchers'],
            ['get', '/api/vouchers/1'], ['post', '/api/vouchers/1/activate']];
    }

    #[DataProvider('routes')]
    public function test_authentication_required(string $method, string $route): void
    {
        $this->{$method.'Json'}($route)->assertUnauthorized();
    }

    public static function quietStates(): array
    {
        return [['pending'], ['for_verification'], ['rejected'], ['cancelled']];
    }

    #[DataProvider('quietStates')]
    public function test_noncompleted_donation_awards_nothing(string $status): void
    {
        $user = $this->signIn();
        $row = $this->participation($user, 'pending');
        $row->forceFill(['status' => $status])->save();
        $this->assertNull(app(PointsService::class)->awardDonation($row));
        $this->getJson('/api/points/summary')->assertOk()->assertExactJson(['current_balance' => 0, 'total_earned' => 0, 'total_spent' => 0]);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    // ========================================
    // AUTOMATIC SCHEDULE AND IDEMPOTENT COMPLETION
    // Assert each donation number, not the opportunity's obsolete points value.
    // ========================================
    public static function schedule(): array
    {
        return [[1, 300], [2, 350], [3, 400], [4, 450], [5, 500], [6, 500], [9, 500]];
    }

    #[DataProvider('schedule')]
    public function test_completed_donation_receives_schedule_amount(int $number, int $amount): void
    {
        $user = $this->signIn();
        for ($i = 0; $i < $number; $i++) {
            $row = $this->complete($user);
        }
        $award = PointTransaction::where('event_key', 'donation:'.$row->id.':reward')->firstOrFail();
        $this->assertSame($amount, $award->amount);
        $this->assertSame('Completed donation #'.$number, $award->description);
        $this->assertDatabaseCount('point_transactions', $number);
        $this->assertSame($amount, Notification::latest('id')->firstOrFail()->data['points_awarded']);
        $this->assertStringContainsString($amount.' points were added.', Notification::latest('id')->firstOrFail()->message);
        Queue::assertPushed(SendImportantPush::class, $number);
    }

    public function test_repeated_completion_and_service_retry_award_once(): void
    {
        $user = $this->signIn();
        $row = $this->complete($user);
        $row->save();
        $row->forceFill(['status' => 'for_verification'])->save();
        $row->forceFill(['status' => 'completed'])->save();
        app(PointsService::class)->awardDonation($row);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 300);
        Queue::assertPushed(SendImportantPush::class, 1);
    }

    public function test_legacy_completed_history_counts_once_and_never_counts_other_donors(): void
    {
        $user = $this->signIn();
        $first = $this->complete($user);
        foreach ([[$user, 'completed', $first->id], [$user, 'completed', null], [$user, 'pending', null], [User::factory()->create(), 'completed', null]] as [$owner, $status, $link]) {
            $record = new DonationRecord;
            $record->forceFill(['user_id' => $owner->id, 'status' => $status, 'donation_participation_id' => $link,
                'location' => 'Legacy venue', 'donation_date' => now(), 'submitted_at' => now()])->save();
        }
        $this->complete($user);
        $this->assertSame(400, PointTransaction::latest('id')->firstOrFail()->amount);
        $this->assertDatabaseCount('point_transactions', 2);
    }

    public function test_completion_transaction_rolls_back_award_and_notice(): void
    {
        $user = $this->signIn();
        $row = $this->participation($user);
        DB::beginTransaction();
        $row->forceFill(['status' => 'completed'])->save();
        DB::rollBack();
        $this->assertSame('for_verification', $row->fresh()->status);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_private_newest_paginated_ledger_and_balance_after_spending(): void
    {
        $user = $this->signIn();
        $this->complete($user);
        $this->complete($user);
        $reward = Reward::factory()->create(['points_cost' => 300]);
        $this->buy($reward)->assertOk();
        $this->complete(User::factory()->create());
        $this->getJson('/api/points/summary')->assertExactJson(['current_balance' => 350, 'total_earned' => 650, 'total_spent' => 300]);
        $this->getJson('/api/points/transactions')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', -300)->assertJsonMissingPath('data.0.event_key')->assertJsonMissingPath('data.0.user_id');
        $this->getJson('/api/points/transactions?type=donation_reward')->assertJsonCount(2, 'data');
    }

    // ========================================
    // REAL CATALOGUE AND ATOMIC SPENDING
    // Rejected requests leave all three tables unchanged; retry keys bind to a reward.
    // ========================================
    public function test_empty_and_current_catalogue_including_sold_out_items(): void
    {
        $this->signIn();
        $this->getJson('/api/rewards')->assertOk()->assertJsonCount(0, 'data');
        $visible = Reward::factory()->create(['stock_quantity' => 0]);
        $hidden = Reward::factory()->create(['active' => false]);
        Reward::factory()->create(['starts_at' => now()->addDay()]);
        Reward::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->getJson('/api/rewards')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/rewards/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/rewards/'.$visible->id)->assertOk();
    }

    public static function unavailable(): array
    {
        return [[['stock_quantity' => 0]], [['active' => false]], [['points_cost' => 301]],
            [['starts_at' => '2099-01-01 00:00:00']], [['ends_at' => '2001-01-01 00:00:00']]];
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_redemption_has_no_partial_writes(array $attributes): void
    {
        $this->complete($this->signIn());
        $reward = Reward::factory()->create($attributes);
        $stock = $reward->stock_quantity;
        $this->buy($reward)->assertUnprocessable();
        $this->assertDatabaseCount('user_vouchers', 0);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame($stock, $reward->fresh()->stock_quantity);
    }

    public function test_success_retry_and_multiple_redemptions_have_exact_debits_and_stock(): void
    {
        $user = $this->signIn();
        $this->complete($user);
        $this->complete($user);
        $reward = Reward::factory()->create(['stock_quantity' => 2]);
        $key = (string) Str::uuid();
        $first = $this->buy($reward, $key)->assertOk()->assertJsonPath('voucher.status', 'available')->assertJsonPath('summary.current_balance', 350)->json('voucher.id');
        $this->buy($reward, $key)->assertOk()->assertJsonPath('voucher.id', $first)->assertJsonPath('reward.stock_quantity', 1);
        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertDatabaseCount('point_transactions', 3);
        $other = Reward::factory()->create();
        $this->buy($other, $key)->assertUnprocessable();
        $this->buy($reward)->assertOk()->assertJsonPath('summary.current_balance', 50);
        $this->assertSame(0, $reward->fresh()->stock_quantity);
        $this->assertDatabaseCount('user_vouchers', 2);
        $reward->points_cost = 500;
        $reward->save();
        $this->assertSame(300, UserVoucher::findOrFail($first)->points_spent);
    }

    public function test_request_fields_and_inventory_validation(): void
    {
        $this->complete($this->signIn());
        $reward = Reward::factory()->create();
        foreach ([[], ['request_key' => 'invalid'], ['request_key' => (string) Str::uuid(), 'points_cost' => 1], ['request_key' => (string) Str::uuid(), 'user_id' => 2]] as $data) {
            $this->postJson('/api/rewards/'.$reward->id.'/redeem', $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('user_vouchers', 0);
        foreach ([['points_cost' => 0], ['stock_quantity' => -1], ['stock_quantity' => 1.5]] as $attributes) {
            try {
                Reward::factory()->create($attributes);
                $this->fail('Invalid inventory accepted.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_failure_after_debit_rolls_back_stock_voucher_and_ledger(): void
    {
        $this->complete($this->signIn());
        $reward = Reward::factory()->create();
        Reward::updating(function () {
            throw new \RuntimeException('Injected inventory failure');
        });
        $this->buy($reward)->assertStatus(500);
        $this->assertDatabaseCount('user_vouchers', 0);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame(2, $reward->fresh()->stock_quantity);
    }

    // ========================================
    // OWNERSHIP, OPAQUE QR AND AUTHORITATIVE TIME
    // Advance server time without any mobile countdown, then read the voucher.
    // ========================================
    public function test_voucher_ownership_filters_and_token_privacy(): void
    {
        $owner = $this->signIn();
        $this->complete($owner);
        $this->complete($owner);
        $reward = Reward::factory()->create();
        $id = $this->buy($reward)->assertOk()->assertJsonPath('voucher.qr_token', null)->assertJsonMissingPath('voucher.voucher_token')->json('voucher.id');
        $this->buy($reward)->assertOk();
        $tokens = UserVoucher::pluck('voucher_token')->all();
        $this->assertNotSame($tokens[0], $tokens[1]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $tokens[0]);
        $this->getJson('/api/vouchers?status=available')->assertJsonCount(2, 'data');
        $this->getJson('/api/vouchers?status=active')->assertJsonCount(0, 'data');
        $this->getJson('/api/vouchers?status=bad')->assertUnprocessable();
        $this->signIn();
        $this->getJson('/api/vouchers/'.$id)->assertNotFound();
        $this->postJson('/api/vouchers/'.$id.'/activate')->assertNotFound();
        $this->getJson('/api/vouchers')->assertJsonCount(0, 'data');
    }

    public function test_activation_cannot_restart_or_accept_client_deadlines_and_reconciles_at_exact_deadline(): void
    {
        $this->complete($this->signIn());
        $id = $this->buy(Reward::factory()->create())->json('voucher.id');
        $route = '/api/vouchers/'.$id;
        $this->postJson($route.'/activate', ['expires_at' => now()->addYear()->toISOString()])->assertUnprocessable();
        $first = $this->postJson($route.'/activate')->assertOk()->assertJsonPath('voucher.status', 'active')->json('voucher');
        $this->assertSame(now()->startOfSecond()->addMinutes(5)->toISOString(), $first['expires_at']);
        $this->assertSame(UserVoucher::findOrFail($id)->voucher_token, $first['qr_token']);
        $this->travel(299)->seconds();
        $this->postJson($route.'/activate')->assertOk()->assertJsonPath('voucher.expires_at', $first['expires_at']);
        $this->getJson($route)->assertJsonPath('voucher.status', 'active');
        $this->travel(1)->seconds();
        $this->getJson($route)->assertOk()->assertJsonPath('voucher.status', 'redeemed')->assertJsonPath('voucher.qr_token', null)
            ->assertJsonPath('voucher.redeemed_at', $first['expires_at']);
        $this->postJson($route.'/activate')->assertJsonPath('voucher.status', 'redeemed')->assertJsonPath('voucher.expires_at', $first['expires_at']);
        $this->getJson('/api/vouchers?status=active')->assertJsonCount(0, 'data');
        $this->getJson('/api/vouchers?status=redeemed')->assertJsonCount(1, 'data');
        $this->getJson('/api/vouchers')->assertJsonCount(1, 'data');
    }

    public function test_list_reconciles_after_app_was_closed_and_expired_cannot_activate(): void
    {
        $this->complete($this->signIn());
        $id = $this->buy(Reward::factory()->create())->json('voucher.id');
        $this->postJson('/api/vouchers/'.$id.'/activate')->assertOk();
        $this->travel(1)->days();
        $this->getJson('/api/vouchers')->assertJsonPath('data.0.status', 'redeemed');
        $this->assertNotNull(UserVoucher::findOrFail($id)->redeemed_at);
        UserVoucher::whereKey($id)->update(['status' => 'expired']);
        $this->postJson('/api/vouchers/'.$id.'/activate')->assertUnprocessable();
    }
}
