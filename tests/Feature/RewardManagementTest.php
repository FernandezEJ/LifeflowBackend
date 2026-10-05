<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\User;
use App\Services\RewardRedemptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RewardManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-09-27 05:00:00');
        Storage::fake('public');
    }

    private function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create(['role' => 'admin']);
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function data(array $changes = []): array
    {
        return [...['name' => 'Grocery voucher', 'category' => 'grocery', 'voucher_value' => 100,
            'points_cost' => 100, 'stock_quantity' => 5, 'status' => 'active'], ...$changes];
    }

    private function credit(User $donor): void
    {
        DB::table('point_transactions')->insert(['user_id' => $donor->id, 'type' => 'donation_reward',
            'amount' => 5000, 'event_key' => 'test-credit', 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function endpoints(): array
    {
        return [['get', '/api/admin/rewards'], ['post', '/api/admin/rewards'], ['get', '/api/admin/rewards/1'],
            ['put', '/api/admin/rewards/1'], ['delete', '/api/admin/rewards/1'], ['post', '/api/admin/rewards/1/restore'],
            ['get', '/api/admin/rewards/analytics'], ['get', '/api/admin/rewards/redemptions']];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_requires_admin_auth_and_changed_password(string $method, string $path): void
    {
        $this->{$method.'Json'}($path)->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->{$method.'Json'}($path)->assertForbidden();
        $this->signIn(User::factory()->create(['role' => 'admin', 'must_change_password' => true]));
        $this->{$method.'Json'}($path)->assertForbidden();
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_both_roles_create_edit_delete_restore_with_audit_events(string $role): void
    {
        $this->signIn(User::factory()->create(['role' => $role]));
        $id = $this->postJson('/api/admin/rewards', $this->data())->assertCreated()->assertJsonPath('reward.status', 'active')->json('reward.id');
        $this->getJson('/api/admin/rewards?search=Grocery&amount=100&status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/rewards/'.$id)->assertOk()->assertJsonPath('reward.points_cost', 100);
        $this->putJson('/api/admin/rewards/'.$id, $this->data(['name' => 'Edited grocery', 'expected_stock' => 5,
            'amount_mode' => 'custom', 'voucher_value' => '125.50', 'stock_quantity' => 2, 'status' => 'inactive']))
            ->assertOk()->assertJsonPath('reward.status', 'inactive')->assertJsonPath('reward.stock_quantity', 2);
        $this->putJson('/api/admin/rewards/'.$id, $this->data(['expected_stock' => 2, 'stock_quantity' => 2]))->assertOk();
        $this->deleteJson('/api/admin/rewards/'.$id)->assertOk()->assertJsonPath('reward.status', 'deleted');
        $this->getJson('/api/admin/rewards?status=deleted')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/admin/rewards/'.$id.'/restore')->assertOk()->assertJsonPath('reward.status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'reward_created', 'target_id' => $id]);
        foreach (['updated', 'activated', 'moved_to_draft', 'deleted', 'restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => 'reward_'.$action, 'target_id' => $id]);
        }
    }

    public static function invalidRewards(): array
    {
        return [['name', '   '], ['voucher_value', -1], ['voucher_value', 0], ['voucher_value', 100001],
            ['points_cost', 0], ['points_cost', 1.5], ['stock_quantity', -1], ['stock_quantity', 1.5],
            ['amount_mode', 'other'], ['status', 'deleted'], ['category', 'Grocery'], ['category', 'health'], ['category', null]];
    }

    #[DataProvider('invalidRewards')]
    public function test_invalid_reward_is_rejected_without_writes(string $field, mixed $value): void
    {
        $this->signIn();
        $this->postJson('/api/admin/rewards', $this->data([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rewards', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_custom_amount_and_image_are_saved_but_excess_decimal_precision_is_rejected(): void
    {
        $this->signIn();
        $this->postJson('/api/admin/rewards', $this->data(['amount_mode' => 'custom', 'voucher_value' => '125.501']))
            ->assertUnprocessable()->assertJsonValidationErrors('voucher_value');
        $this->post('/api/admin/rewards', $this->data(['amount_mode' => 'custom', 'voucher_value' => '125.50',
            'image' => UploadedFile::fake()->image('voucher.png')]), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('reward.voucher_value', '125.50');
        Storage::disk('public')->assertExists(Reward::first()->image_path);
    }

    public function test_history_survives_edit_delete_retry_and_activation_and_stale_stock_is_rejected(): void
    {
        $donor = User::factory()->create();
        $this->credit($donor);
        $admin = $this->signIn();
        $id = $this->postJson('/api/admin/rewards', $this->data())->assertCreated()->json('reward.id');
        $this->signIn($donor);
        $key = (string) Str::uuid();
        $voucherId = $this->postJson('/api/rewards/'.$id.'/redeem', ['request_key' => $key])->assertOk()->json('voucher.id');
        $this->signIn($admin);
        $this->putJson('/api/admin/rewards/'.$id, $this->data(['expected_stock' => 5]))->assertConflict();
        $this->assertDatabaseHas('rewards', ['id' => $id, 'stock_quantity' => 4]);
        $this->putJson('/api/admin/rewards/'.$id, $this->data(['expected_stock' => 4, 'stock_quantity' => 4,
            'name' => 'New name', 'voucher_value' => 200, 'points_cost' => 200]))->assertOk();
        $this->deleteJson('/api/admin/rewards/'.$id)->assertOk();
        $this->signIn($donor);
        $this->getJson('/api/rewards')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/rewards/'.$id.'/redeem', ['request_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/rewards/'.$id.'/redeem', ['request_key' => $key])->assertOk()->assertJsonPath('voucher.id', $voucherId);
        $this->getJson('/api/vouchers/'.$voucherId)->assertOk()->assertJsonPath('voucher.reward.name', 'Grocery voucher')
            ->assertJsonPath('voucher.reward.voucher_value', '100.00')->assertJsonPath('voucher.points_spent', 100);
        $this->postJson('/api/vouchers/'.$voucherId.'/activate')->assertOk()->assertJsonPath('voucher.status', 'active');
        $this->travel(5)->minutes();
        $this->getJson('/api/vouchers/'.$voucherId)->assertOk()->assertJsonPath('voucher.status', 'redeemed')->assertJsonPath('voucher.qr_token', null);
        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertDatabaseCount('point_transactions', 2);
        $this->assertDatabaseHas('rewards', ['id' => $id, 'stock_quantity' => 4]);
    }

    public function test_recovery_deadline_and_purge_preserve_owned_history(): void
    {
        $donor = User::factory()->create();
        $this->credit($donor);
        $reward = Reward::factory()->create(['points_cost' => 100]);
        app(RewardRedemptionService::class)->redeem($donor->id, $reward->id, (string) Str::uuid());
        $reward->delete();
        $unused = Reward::factory()->create();
        $unused->delete();
        $this->travel(30)->days();
        $this->signIn();
        $this->postJson('/api/admin/rewards/'.$reward->id.'/restore')->assertGone();
        $this->artisan('rewards:purge-deleted')->assertExitCode(0);
        $this->assertNotNull(Reward::withTrashed()->find($reward->id));
        $this->assertNull(Reward::withTrashed()->find($unused->id));
        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertDatabaseCount('point_transactions', 2);
    }

    public function test_analytics_uses_purchase_ledger_local_period_and_deterministic_top_three(): void
    {
        $donor = User::factory()->create();
        $this->credit($donor);
        $rewards = Reward::factory()->count(4)->create(['points_cost' => 100, 'stock_quantity' => 5, 'voucher_value' => 100]);
        foreach ([$rewards[0], $rewards[0], $rewards[1], $rewards[2], $rewards[3]] as $index => $reward) {
            $voucher = app(RewardRedemptionService::class)->redeem($donor->id, $reward->id, (string) Str::uuid());
            DB::table('point_transactions')->where('source_type', 'user_voucher')->where('source_id', $voucher->id)
                ->update(['created_at' => $index === 0 ? '2026-08-31 16:00:00' : '2026-09-02 00:00:00']);
        }
        $older = app(RewardRedemptionService::class)->redeem($donor->id, $rewards[3]->id, (string) Str::uuid());
        DB::table('point_transactions')->where('source_type', 'user_voucher')->where('source_id', $older->id)->update(['created_at' => '2025-12-31 15:59:59']);
        $rewards[3]->refresh()->delete();
        Reward::factory()->create(['active' => false, 'stock_quantity' => 80]);
        Reward::factory()->create(['starts_at' => now()->addDay(), 'stock_quantity' => 90]);
        $this->signIn();

        $response = $this->getJson('/api/admin/rewards/analytics?year=2026')->assertOk()
            ->assertJsonPath('summary.total_redemptions', 5)->assertJsonPath('summary.total_points_spent', 500)
            ->assertJsonPath('summary.active_inventory', 11)->assertJsonCount(12, 'monthly_redemptions')
            ->assertJsonPath('monthly_redemptions.7.redemptions', 0)->assertJsonPath('monthly_redemptions.8.redemptions', 5)
            ->assertJsonCount(3, 'top_rewards')->assertJsonPath('top_rewards.0.redemptions', 2);
        $this->assertSame([$rewards[0]->id, $rewards[1]->id, $rewards[2]->id], array_column($response->json('top_rewards'), 'reward_id'));
        $this->getJson('/api/admin/rewards/analytics?year=2026&month=9')->assertOk()->assertJsonCount(1, 'monthly_redemptions')->assertJsonPath('summary.total_redemptions', 5);
        $this->getJson('/api/admin/rewards/analytics?year=2025')->assertOk()->assertJsonPath('summary.total_redemptions', 1);
        $this->getJson('/api/admin/rewards/analytics?year=2026&month=8')->assertOk()->assertJsonPath('summary.total_redemptions', 0);
        $rows = $this->getJson('/api/admin/rewards/redemptions?year=2026&month=9&amount=100&status=available')->assertOk()->assertJsonCount(5, 'data');
        $this->assertStringNotContainsString('voucher_token', $rows->getContent());
        $this->assertStringNotContainsString('redemption_key', $rows->getContent());
        $this->getJson('/api/admin/rewards/redemptions?search=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('roles')]
    public function test_redemption_categories_combine_with_search_and_status_without_changing_lifecycle(string $role): void
    {
        $donor = User::factory()->create(['name' => 'Category buyer']);
        $this->credit($donor);
        $expected = [];
        foreach (['grocery', 'medicine', 'fitness', 'other'] as $category) {
            $reward = Reward::factory()->create(['name' => 'Original '.$category, 'category' => $category,
                'points_cost' => 100, 'voucher_value' => 125.50, 'stock_quantity' => 4]);
            foreach (['available', 'active', 'redeemed', 'expired'] as $status) {
                $voucher = app(RewardRedemptionService::class)->redeem($donor->id, $reward->id, (string) Str::uuid());
                DB::table('user_vouchers')->where('id', $voucher->id)->update(['status' => $status,
                    'expires_at' => $status === 'active' ? now()->addMinutes(5) : null]);
                $expected[$category][$status] = $voucher->id;
            }
            $reward->refresh()->update(['name' => 'Renamed '.$category]);
            $reward->delete();
        }
        $beforeVouchers = DB::table('user_vouchers')->orderBy('id')->get()->toArray();
        $beforeLedger = DB::table('point_transactions')->orderBy('id')->get()->toArray();
        $this->signIn(User::factory()->create(['role' => $role]));
        foreach ($expected as $category => $statuses) {
            foreach ($statuses as $status => $id) {
                $this->getJson('/api/admin/rewards/redemptions?category='.$category.'&status='.$status.'&search=Original&amount=125.50')
                    ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
                    ->assertJsonPath('data.0.category', $category)->assertJsonPath('data.0.status', $status)
                    ->assertJsonPath('data.0.reward', 'Original '.$category);
            }
        }
        $this->getJson('/api/admin/rewards/redemptions?category=all&status=all&search=Category')->assertOk()->assertJsonCount(16, 'data');
        $this->getJson('/api/admin/rewards/redemptions?category=fitness&search=absent')->assertOk()->assertJsonCount(0, 'data');
        $this->assertEquals($beforeVouchers, DB::table('user_vouchers')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeLedger, DB::table('point_transactions')->orderBy('id')->get()->toArray());
    }

    #[DataProvider('roles')]
    public function test_year_only_analytics_include_all_twelve_months_with_manila_year_boundaries(string $role): void
    {
        $donor = User::factory()->create();
        $this->credit($donor);
        $reward = Reward::factory()->create(['category' => 'other', 'points_cost' => 100, 'stock_quantity' => 5]);
        foreach (['2025-12-31 15:59:59', '2025-12-31 16:00:00', '2026-06-15 00:00:00',
            '2026-12-31 15:59:59', '2026-12-31 16:00:00'] as $date) {
            $voucher = app(RewardRedemptionService::class)->redeem($donor->id, $reward->id, (string) Str::uuid());
            DB::table('point_transactions')->where('source_type', 'user_voucher')->where('source_id', $voucher->id)
                ->update(['created_at' => $date]);
        }
        $this->signIn(User::factory()->create(['role' => $role]));
        $response = $this->getJson('/api/admin/rewards/analytics?year=2026')->assertOk()->assertJsonPath('year', 2026)
            ->assertJsonPath('month', null)->assertJsonPath('summary.total_redemptions', 3)
            ->assertJsonPath('summary.total_points_spent', 300)->assertJsonCount(12, 'monthly_redemptions')
            ->assertJsonPath('monthly_redemptions.0.redemptions', 1)->assertJsonPath('monthly_redemptions.5.redemptions', 1)
            ->assertJsonPath('monthly_redemptions.11.redemptions', 1)->assertJsonPath('top_rewards.0.redemptions', 3);
        $this->assertSame(array_map(fn (int $month): string => sprintf('2026-%02d', $month), range(1, 12)),
            array_column($response->json('monthly_redemptions'), 'period'));
        $this->assertSame(3, array_sum(array_column($response->json('monthly_redemptions'), 'redemptions')));
        $this->getJson('/api/admin/rewards/analytics?year=2025')->assertOk()->assertJsonPath('summary.total_redemptions', 1);
        $this->getJson('/api/admin/rewards/analytics?year=2027')->assertOk()->assertJsonPath('summary.total_redemptions', 1);
    }

    public static function invalidFilters(): array
    {
        return [['year=1999'], ['year=9999'], ['month=0'], ['month=13'], ['month=Sep'], ['amount=-1'], ['status=other'],
            ['category=health'], ['category=Grocery'], ['category[]=fitness']];
    }

    public function test_failed_purchases_and_unrelated_ledger_rows_do_not_enter_analytics(): void
    {
        $donor = User::factory()->create();
        $reward = Reward::factory()->create(['points_cost' => 100, 'stock_quantity' => 1]);
        $this->signIn($donor);
        $this->postJson('/api/rewards/'.$reward->id.'/redeem', ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('user_vouchers', 0);
        $this->assertDatabaseHas('rewards', ['id' => $reward->id, 'stock_quantity' => 1]);
        $this->credit($donor);
        // A legacy voucher without a successful debit is not a purchase metric.
        DB::table('user_vouchers')->insert(['user_id' => $donor->id, 'reward_id' => $reward->id,
            'points_spent' => 100, 'status' => 'available', 'voucher_token' => 'private-unfunded',
            'redemption_key' => (string) Str::uuid(), 'created_at' => now()]);
        $this->signIn();

        $this->getJson('/api/admin/rewards/analytics')->assertOk()->assertJsonPath('summary.total_redemptions', 0)
            ->assertJsonPath('summary.total_points_spent', 0)->assertJsonPath('top_rewards', []);
        $this->getJson('/api/admin/rewards/redemptions')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422(string $filter): void
    {
        $this->signIn();
        $this->getJson('/api/admin/rewards/analytics?'.$filter)->assertUnprocessable();
        $this->getJson('/api/admin/rewards?'.$filter)->assertUnprocessable();
        $this->getJson('/api/admin/rewards/redemptions?'.$filter)->assertUnprocessable();
    }

    public static function categories(): array
    {
        return [['grocery'], ['medicine'], ['fitness'], ['other']];
    }

    #[DataProvider('categories')]
    public function test_manual_values_and_categories_persist_without_amount_mode(string $category): void
    {
        $this->signIn();
        $id = $this->postJson('/api/admin/rewards', $this->data(['name' => 'LifeFlow voucher', 'category' => $category,
            'voucher_value' => '152.75']))->assertCreated()->assertJsonPath('reward.category', $category)
            ->assertJsonPath('reward.voucher_value', '152.75')->assertJsonPath('reward.amount_mode', 'custom')->json('reward.id');
        $this->assertDatabaseHas('rewards', ['id' => $id, 'category' => $category, 'voucher_value' => '152.75',
            'stock_quantity' => 5, 'points_cost' => 100]);
        $this->putJson('/api/admin/rewards/'.$id, $this->data(['category' => $category, 'expected_stock' => 5,
            'voucher_value' => '0.01']))->assertOk()->assertJsonPath('reward.voucher_value', '0.01');
        $this->assertDatabaseHas('rewards', ['id' => $id, 'category' => $category, 'voucher_value' => '0.01']);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $id, 'action' => 'reward_updated']);
    }

    public function test_category_search_and_status_filters_combine_and_all_category_keeps_results(): void
    {
        $this->signIn();
        foreach (['grocery', 'medicine', 'fitness', 'other'] as $category) {
            foreach (['active', 'inactive', 'deleted'] as $status) {
                $match = Reward::factory()->create(['name' => 'Matching voucher', 'category' => $category,
                    'active' => $status === 'active', 'deleted_at' => $status === 'deleted' ? now() : null]);
                Reward::factory()->create(['name' => 'Unrelated voucher', 'category' => $category,
                    'active' => $status === 'active', 'deleted_at' => $status === 'deleted' ? now() : null]);
                $this->getJson('/api/admin/rewards?category='.$category.'&status='.$status.'&search=Matching')
                    ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
                    ->assertJsonPath('data.0.category', $category)->assertJsonPath('data.0.status', $status);
            }
        }
        $this->getJson('/api/admin/rewards?category=all&status=inactive')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/admin/rewards?category=medicine&status=deleted')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/rewards?category=fitness&search=absent')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_available_stock_is_only_active_remaining_inventory(): void
    {
        $this->signIn();
        Reward::factory()->create(['stock_quantity' => 3]);
        Reward::factory()->create(['stock_quantity' => 4]);
        Reward::factory()->create(['stock_quantity' => 0]);
        Reward::factory()->create(['active' => false, 'stock_quantity' => 80]);
        Reward::factory()->create(['deleted_at' => now(), 'stock_quantity' => 90]);

        $rows = $this->getJson('/api/admin/rewards')->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame(7, collect($rows)->where('status', 'active')->sum('stock_quantity'));
        $this->getJson('/api/admin/rewards/analytics')->assertOk()->assertJsonPath('summary.active_inventory', 7);
        $this->assertDatabaseHas('rewards', ['active' => false, 'stock_quantity' => 80]);
        $this->assertDatabaseHas('rewards', ['stock_quantity' => 90]);
    }

    public function test_category_migration_preserves_legacy_inventory_and_owned_history(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
        $legacy = Reward::factory()->create(['name' => 'Legacy grocery', 'amount_mode' => 'fixed',
            'voucher_value' => 100, 'stock_quantity' => 5, 'points_cost' => 100]);
        $donor = User::factory()->create();
        $this->credit($donor);
        $voucher = app(RewardRedemptionService::class)->redeem($donor->id, $legacy->id, (string) Str::uuid());
        $beforeReward = (array) DB::table('rewards')->find($legacy->id);
        $beforeVoucher = (array) DB::table('user_vouchers')->find($voucher->id);
        $beforeLedger = DB::table('point_transactions')->orderBy('id')->get()->toArray();

        $migration = require database_path('migrations/2026_10_04_123132_add_category_to_rewards_table.php');
        $migration->up();

        $afterReward = (array) DB::table('rewards')->find($legacy->id);
        $this->assertSame('grocery', $afterReward['category']);
        unset($afterReward['category']);
        $this->assertSame($beforeReward, $afterReward);
        $this->assertSame($beforeVoucher, (array) DB::table('user_vouchers')->find($voucher->id));
        $this->assertEquals($beforeLedger, DB::table('point_transactions')->orderBy('id')->get()->toArray());
        $this->signIn();
        $this->getJson('/api/admin/rewards?category=grocery')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_mode', 'fixed')->assertJsonPath('data.0.category', 'grocery');
        $this->putJson('/api/admin/rewards/'.$legacy->id, $this->data(['expected_stock' => 4, 'stock_quantity' => 4,
            'category' => 'fitness', 'voucher_value' => '152.75']))->assertOk()->assertJsonPath('reward.amount_mode', 'custom');
        $this->signIn($donor);
        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.reward.name', 'Legacy grocery')
            ->assertJsonPath('voucher.reward.voucher_value', '100.00')->assertJsonPath('voucher.points_spent', 100);
    }
}
