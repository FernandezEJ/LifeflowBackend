<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use App\Services\RewardClaimCode;
use App\Services\RewardRedemptionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RewardClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-14 04:00:00');
    }

    private function signIn(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('claim-test')->plainTextToken);
    }

    private function purchase(?User $owner = null, ?Reward $reward = null): UserVoucher
    {
        $owner ??= User::factory()->create(['name' => 'Claim Donor']);
        $reward ??= Reward::factory()->create(['name' => 'LifeFlow shirt', 'category' => 'other',
            'description' => 'One shirt, size Medium', 'points_cost' => 300, 'stock_quantity' => 5]);
        DB::table('point_transactions')->insert(['user_id' => $owner->id, 'type' => 'donation_reward',
            'amount' => 300, 'event_key' => 'test-credit:'.Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        return app(RewardRedemptionService::class)->redeem($owner->id, $reward->id, (string) Str::uuid());
    }

    private function activate(UserVoucher $voucher): string
    {
        $this->signIn($voucher->user);

        return $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertOk()
            ->assertJsonPath('voucher.claim_status', 'pending_claim')->json('voucher.claim_code');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function history(): array
    {
        $tables = [];
        foreach (['user_vouchers', 'rewards', 'point_transactions', 'audit_logs'] as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $tables;
    }

    public function test_purchase_debits_once_and_does_not_issue_a_code(): void
    {
        $voucher = $this->purchase();
        $this->signIn($voucher->user);
        $before = $this->history();

        $response = $this->postJson('/api/rewards/'.$voucher->reward_id.'/redeem', ['request_key' => $voucher->redemption_key])
            ->assertOk()->assertJsonPath('voucher.id', $voucher->id)->assertJsonPath('voucher.claim_status', 'available')
            ->assertJsonPath('voucher.claim_code', null)->assertJsonPath('voucher.expires_at', null);

        $this->assertSame($before, $this->history());
        $this->assertDatabaseCount('point_transactions', 2);
        $this->assertDatabaseHas('rewards', ['id' => $voucher->reward_id, 'stock_quantity' => 4]);
        $this->assertDatabaseHas('user_vouchers', ['id' => $voucher->id, 'claim_code_encrypted' => null, 'claim_code_digest' => null]);
        foreach (['qr_token', 'voucher_token', 'claim_code_encrypted', 'claim_code_digest', 'claimed_by_user_id'] as $secret) {
            $response->assertJsonMissingPath('voucher.'.$secret);
        }
    }

    public function test_activation_code_is_encrypted_keyed_owner_only_and_stable_for_weeks(): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $stored = (array) DB::table('user_vouchers')->find($voucher->id);
        $ledger = $this->history()['point_transactions'];
        $this->assertMatchesRegularExpression('/\ALFW-[A-Z0-9]{6}\z/', $code);
        $this->assertStringNotContainsString($code, $stored['claim_code_encrypted']);
        $this->assertSame($code, Crypt::decryptString($stored['claim_code_encrypted']));
        $this->assertSame(hash_hmac('sha256', "lifeflow:reward-claim:v1\0".$code, Crypt::getKey()), $stored['claim_code_digest']);
        $this->assertNotSame(hash('sha256', $code), $stored['claim_code_digest']);
        foreach ([5, 60, 30240] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertOk()->assertJsonPath('voucher.claim_code', $code)->assertJsonPath('voucher.expires_at', null);
            $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.claim_status', 'pending_claim')
                ->assertJsonPath('voucher.claim_code', $code)->assertJsonPath('voucher.redeemed_at', null)->assertHeader('Cache-Control', 'no-store, private');
            $response = $this->getJson('/api/vouchers')->assertOk()->assertJsonPath('data.0.claim_code', $code);
            foreach (['claim_code_encrypted', 'claim_code_digest', 'voucher_token', 'qr_token', 'claimed_by_user_id'] as $secret) {
                $response->assertJsonMissingPath('data.0.'.$secret);
            }
        }
        $this->assertSame($stored, (array) DB::table('user_vouchers')->find($voucher->id));
        $this->assertSame($ledger, $this->history()['point_transactions']);
        $this->assertDatabaseHas('rewards', ['id' => $voucher->reward_id, 'stock_quantity' => 4]);
        $this->signIn(User::factory()->create());
        $this->getJson('/api/vouchers/'.$voucher->id)->assertNotFound();
        $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertNotFound();
        $this->getJson('/api/vouchers')->assertJsonCount(0, 'data');
    }

    public static function clientActivationFields(): array
    {
        return [['claim_code', 'LFW-ABC123'], ['expires_at', '2028-01-01'], ['status', 'redeemed'],
            ['claimed_at', '2026-10-14'], ['claimed_by_user_id', 1], ['points_spent', 0], ['stock_quantity', 100]];
    }

    #[DataProvider('clientActivationFields')]
    public function test_activation_accepts_no_client_fields(string $field, mixed $value): void
    {
        $voucher = $this->purchase();
        $this->signIn($voucher->user);
        $before = $this->history();

        $this->postJson('/api/vouchers/'.$voucher->id.'/activate', [$field => $value])->assertUnprocessable();

        $this->assertSame($before, $this->history());
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_verify_is_read_only_and_uses_snapshots_even_after_catalog_changes(string $role): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $voucher->reward->update(['name' => 'Changed shirt', 'description' => 'Size Large', 'active' => false,
            'ends_at' => now()->subDay()]);
        $voucher->reward->delete();
        $this->travel(4)->weeks();
        $this->signIn(User::factory()->create(['role' => $role]));
        $before = $this->history();

        $response = $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => ' '.strtolower($code).' '])
            ->assertOk()->assertJsonPath('claim.voucher_id', $voucher->id)->assertJsonPath('claim.donor_name', 'Claim Donor')
            ->assertJsonPath('claim.reward.name', 'LifeFlow shirt')->assertJsonPath('claim.reward.description', 'One shirt, size Medium')
            ->assertJsonPath('claim.claim_status', 'pending_claim')->assertJsonPath('claim.claimed_at', null)->assertJsonPath('claim.can_claim', true);

        $this->assertSame($before, $this->history());
        $this->assertStringNotContainsString($code, $response->getContent());
        foreach (['claim_code', 'claim_code_digest', 'claim_code_encrypted', 'voucher_token', 'email', 'mobile_number', 'claimed_by_user_id'] as $secret) {
            $response->assertJsonMissingPath('claim.'.$secret);
        }
        $this->getJson('/api/admin/rewards/redemptions')->assertOk()->assertJsonPath('data.0.claim_status', 'pending_claim');
    }

    #[DataProvider('staffRoles')]
    public function test_confirmation_records_one_handover_and_owner_loses_usable_code(string $role): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $actor = User::factory()->create(['role' => $role]);
        $this->signIn($actor);
        $before = $this->history();
        $time = now()->startOfSecond()->toISOString();

        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.claim_status', 'claimed')->assertJsonPath('claim.claimed_at', $time)->assertJsonPath('claim.can_claim', false);

        $claimed = $voucher->fresh();
        $this->assertSame($actor->id, $claimed->claimed_by_user_id);
        $this->assertSame($time, $claimed->redeemed_at->toISOString());
        $this->assertSame($before['point_transactions'], $this->history()['point_transactions']);
        $this->assertSame($before['rewards'], $this->history()['rewards']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $actor->id, 'actor_role' => $role,
            'action' => 'reward_claimed', 'module' => 'rewards', 'target_type' => 'user_voucher', 'target_id' => $voucher->id]);
        $this->assertStringNotContainsString($code, AuditLog::firstOrFail()->toJson());
        $this->assertStringNotContainsString($claimed->claim_code_digest, AuditLog::firstOrFail()->toJson());
        $after = $this->history();
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertConflict();
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.status_label', 'Already Claimed')->assertJsonPath('claim.claimed_at', $time)->assertJsonPath('claim.can_claim', false);
        $this->assertSame($after, $this->history());
        $this->signIn($voucher->user);
        foreach (['get', 'post'] as $method) {
            $this->{$method.'Json'}('/api/vouchers/'.$voucher->id.($method === 'post' ? '/activate' : ''))->assertOk()
                ->assertJsonPath('voucher.claim_status', 'claimed')->assertJsonPath('voucher.claim_code', null)
                ->assertJsonPath('voucher.claimed_at', $time)->assertJsonMissingPath('voucher.claimed_by_user_id');
        }
        $this->getJson('/api/vouchers')->assertJsonPath('data.0.claim_code', null);
        $this->assertSame($after, $this->history());
    }

    public static function deniedStaff(): array
    {
        return [['donor', false, false], ['donor', true, false], ['admin', true, false],
            ['super_admin', true, false], ['admin', false, true], ['super_admin', false, true]];
    }

    #[DataProvider('deniedStaff')]
    public function test_staff_endpoints_deny_donors_deactivated_staff_and_initial_password_sessions(string $role, bool $inactive, bool $password): void
    {
        $actor = User::factory()->create(['role' => $role, 'deactivated_at' => $inactive ? now() : null, 'must_change_password' => $password]);
        foreach (['verify', 'confirm'] as $action) {
            $this->signIn($actor);
            $this->postJson('/api/admin/rewards/claims/'.$action, ['claim_code' => 'LFW-ABC123'])->assertForbidden();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_claim_endpoints_require_authentication(): void
    {
        foreach (['verify', 'confirm'] as $action) {
            $this->postJson('/api/admin/rewards/claims/'.$action, ['claim_code' => 'LFW-ABC123'])->assertUnauthorized();
        }
    }

    public static function deniedDonors(): array
    {
        return [['admin', false], ['super_admin', false], ['donor', true]];
    }

    #[DataProvider('deniedDonors')]
    public function test_mobile_rewards_routes_are_active_donor_only(string $role, bool $inactive): void
    {
        $voucher = $this->purchase();
        $actor = User::factory()->create(['role' => $role, 'deactivated_at' => $inactive ? now() : null]);
        $this->signIn($actor);
        $before = $this->history();

        $this->postJson('/api/rewards/'.$voucher->reward_id.'/redeem', ['request_key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertForbidden();
        $this->getJson('/api/vouchers/'.$voucher->id)->assertForbidden();
        $this->getJson('/api/vouchers')->assertForbidden();

        $this->assertSame($before, $this->history());
    }

    public static function invalidCodes(): array
    {
        return [[[]], [['claim_code' => null]], [['claim_code' => 123]], [['claim_code' => ['LFW-ABC123']]],
            [['claim_code' => 'ABC123']], [['claim_code' => 'LFW-ABC12']], [['claim_code' => 'LFW-ABC1234']],
            [['claim_code' => 'LFW-ABC 12']], [['claim_code' => "LFW-ABC\n12"]], [['claim_code' => 'LFW-ÁBC123']],
            [['claim_code' => 'LFW-ABC123', 'claimed_at' => '2026-10-14']], [['claim_code' => 'LFW-ABC123', 'claimed_by_user_id' => 1]]];
    }

    #[DataProvider('invalidCodes')]
    public function test_bad_syntax_or_extra_staff_fields_do_not_lookup_or_mutate(array $body): void
    {
        $this->signIn(User::factory()->create(['role' => 'admin']));
        foreach (['verify', 'confirm'] as $action) {
            $this->postJson('/api/admin/rewards/claims/'.$action, $body)->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unknown_code_has_generic_error_and_no_query_or_code_logging(): void
    {
        $this->signIn(User::factory()->create(['role' => 'admin']));
        Log::spy();
        foreach (['verify', 'confirm'] as $action) {
            $this->postJson('/api/admin/rewards/claims/'.$action, ['claim_code' => 'LFW-ABC123'])
                ->assertNotFound()->assertJsonPath('message', 'Claim code not found.');
        }
        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public static function legacyStates(): array
    {
        return [['available'], ['active'], ['redeemed'], ['expired']];
    }

    #[DataProvider('legacyStates')]
    public function test_legacy_history_is_not_a_claim_and_only_available_or_active_can_convert(string $status): void
    {
        $voucher = $this->purchase();
        $voucher->forceFill(['status' => $status, 'activated_at' => now()->subWeeks(2), 'expires_at' => now()->subWeeks(2)->addMinutes(5),
            'redeemed_at' => $status === 'redeemed' ? now()->subWeeks(2)->addMinutes(5) : null])->save();
        $actor = User::factory()->create(['role' => 'admin']);
        $audit = $actor->auditLogs()->create(['actor_role' => 'admin', 'action' => 'reward_created', 'module' => 'rewards', 'target_id' => $voucher->reward_id]);
        $auditBefore = $audit->fresh()->getRawOriginal();
        $this->signIn($voucher->user);
        $before = $this->history();

        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()
            ->assertJsonPath('voucher.claim_status', $status === 'available' ? 'available' : 'legacy')
            ->assertJsonPath('voucher.claimed_at', null)->assertJsonPath('voucher.claim_code', null);
        $this->getJson('/api/vouchers')->assertOk();
        $this->assertSame($before, $this->history());
        if ($status === 'expired') {
            $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertUnprocessable();
            $this->assertSame($before, $this->history());
        } elseif ($status === 'redeemed') {
            $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertOk()->assertJsonPath('voucher.claim_status', 'legacy')
                ->assertJsonPath('voucher.claim_code', null)->assertJsonPath('voucher.claimed_at', null);
            $this->assertSame($before, $this->history());
        } else {
            $code = $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertOk()
                ->assertJsonPath('voucher.claim_status', 'pending_claim')->assertJsonPath('voucher.expires_at', null)->json('voucher.claim_code');
            $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertJsonPath('voucher.claim_code', $code);
            $after = $voucher->fresh();
            $this->assertSame($voucher->reward_snapshot, $after->reward_snapshot);
            $this->assertSame($voucher->voucher_token, $after->voucher_token);
            $this->assertSame($voucher->points_spent, $after->points_spent);
            $this->assertNull($after->claimed_at);
            $this->assertNull($after->claimed_by_user_id);
            $this->assertSame($before['point_transactions'], $this->history()['point_transactions']);
            $this->assertSame($before['rewards'], $this->history()['rewards']);
        }
        $this->assertSame($auditBefore, $audit->fresh()->getRawOriginal());
    }

    public function test_generated_collision_retries_without_spending_or_changing_an_existing_code(): void
    {
        $first = $this->purchase();
        $code = $this->activate($first);
        $second = $this->purchase();
        $this->partialMock(RewardClaimCode::class)->shouldReceive('generate')->twice()->andReturn($code, 'LFW-ABC123');
        $before = $this->history()['point_transactions'];

        $this->assertSame('LFW-ABC123', $this->activate($second));

        $this->assertSame($code, Crypt::decryptString($first->fresh()->claim_code_encrypted));
        $this->assertSame(2, UserVoucher::whereNotNull('claim_code_digest')->distinct()->count('claim_code_digest'));
        $this->assertSame($before, $this->history()['point_transactions']);
    }

    public function test_unique_index_collision_rolls_back_the_attempt_and_retries_cleanly(): void
    {
        $voucher = $this->purchase();
        $this->partialMock(RewardClaimCode::class)->shouldReceive('generate')->twice()->andReturn('LFW-ABC123', 'LFW-XYZ789');
        $collision = true;
        UserVoucher::updating(function (UserVoucher $row) use (&$collision): void {
            if ($collision) {
                $collision = false;
                DB::table('user_vouchers')->insert(['user_id' => $row->user_id, 'reward_id' => $row->reward_id,
                    'points_spent' => 300, 'status' => 'active', 'voucher_token' => 'collision-probe', 'redemption_key' => (string) Str::uuid(),
                    'claim_code_digest' => $row->claim_code_digest]);
            }
        });
        Log::spy();

        $this->assertSame('LFW-XYZ789', $this->activate($voucher));

        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertDatabaseCount('point_transactions', 2);
        Log::shouldNotHaveReceived('error');
    }

    public function test_previous_app_key_keeps_owner_decryption_lookup_and_global_collision_checks_working(): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $oldKey = Crypt::getKey();
        $oldDigest = $voucher->fresh()->claim_code_digest;
        // These are isolated test configuration values, not changes to the real APP_KEY/.env.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.previous_keys' => ['base64:'.base64_encode($oldKey)]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.claim_code', $code);
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk()->assertJsonPath('claim.voucher_id', $voucher->id);
        $second = $this->purchase();
        $this->partialMock(RewardClaimCode::class)->shouldReceive('generate')->twice()->andReturn($code, 'LFW-XYZ789');
        $this->assertSame('LFW-XYZ789', $this->activate($second));
        $this->assertSame($oldDigest, $voucher->fresh()->claim_code_digest);
        $this->assertNotSame($oldDigest, app(RewardClaimCode::class)->digests($code)[0]);
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.claim_status', 'claimed');
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.status_label', 'Already Claimed');
        $this->signIn($voucher->user);
        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.claim_code', null);
        $this->assertSame($oldDigest, $voucher->fresh()->claim_code_digest);
    }

    public function test_owned_reward_can_activate_and_be_claimed_after_catalog_inactivation_and_end_date(): void
    {
        $voucher = $this->purchase();
        $voucher->reward->update(['active' => false, 'name' => 'Changed item', 'description' => 'Changed size',
            'ends_at' => now()->addMinute()]);
        $before = $this->history();
        $this->travel(8)->weeks();
        $code = $this->activate($voucher);
        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.expires_at', null)
            ->assertJsonPath('voucher.reward.name', 'LifeFlow shirt');
        $this->signIn(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.can_claim', true)->assertJsonPath('claim.reward.description', 'One shirt, size Medium');
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.claim_status', 'claimed');

        $this->assertSame($before['point_transactions'], $this->history()['point_transactions']);
        $this->assertSame($before['rewards'], $this->history()['rewards']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('legacyStates')]
    public function test_staff_cannot_confirm_legacy_vouchers_using_old_tokens_even_if_they_look_like_claim_codes(string $status): void
    {
        $voucher = $this->purchase();
        $voucher->forceFill(['status' => $status, 'voucher_token' => 'LFW-ABC123',
            'expires_at' => now()->subWeek(), 'redeemed_at' => $status === 'redeemed' ? now()->subWeek() : null])->save();
        $this->signIn(User::factory()->create(['role' => 'super_admin']));
        $before = $this->history();

        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => 'LFW-ABC123'])->assertNotFound();
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => 'LFW-ABC123'])->assertNotFound();

        $this->assertSame($before, $this->history());
        $this->assertNull($voucher->fresh()->claimed_at);
        $this->assertNull($voucher->fresh()->claimed_by_user_id);
    }

    public function test_staff_limit_is_shared_between_verify_confirm_and_all_tokens_for_one_user(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->signIn($actor);
        for ($index = 0; $index < 20; $index++) {
            $this->postJson('/api/admin/rewards/claims/'.($index % 2 ? 'verify' : 'confirm'), ['claim_code' => 'LFW-ABC123'])->assertNotFound();
        }
        $this->signIn($actor);
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => 'LFW-ABC123'])->assertTooManyRequests();
        $this->travel(1)->minutes();
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => 'LFW-ABC123'])->assertNotFound();
    }

    public function test_ip_limit_cannot_be_bypassed_with_multiple_staff_accounts(): void
    {
        foreach (range(1, 3) as $index) {
            $this->signIn(User::factory()->create(['role' => 'admin']));
            foreach (range(1, 20) as $attempt) {
                $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => 'LFW-ABC123'])->assertNotFound();
            }
        }
        $this->signIn(User::factory()->create(['role' => 'super_admin']));
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => 'LFW-ABC123'])->assertTooManyRequests();
    }

    public function test_claim_audit_failure_rolls_back_attribution_and_status(): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $before = $this->history();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Injected audit failure');
        });

        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertStatus(500);

        $this->assertSame($before, $this->history());
    }

    public function test_analytics_reports_keep_purchase_meaning_and_never_expose_claim_secrets(): void
    {
        $pending = $this->purchase();
        $code = $this->activate($pending);
        $legacy = $this->purchase();
        $legacy->forceFill(['status' => 'redeemed', 'redeemed_at' => now()->subMinute()])->save();
        $this->signIn(User::factory()->create(['role' => 'super_admin']));

        $this->getJson('/api/admin/rewards/analytics?year=2026')->assertOk()->assertJsonPath('summary.total_redemptions', 2)
            ->assertJsonPath('summary.total_points_spent', 600);
        $response = $this->getJson('/api/admin/rewards/redemptions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.claim_status', 'legacy')->assertJsonPath('data.0.claimed_at', null)
            ->assertJsonPath('data.1.claim_status', 'pending_claim');
        $report = $this->getJson('/api/admin/system-reports?year=2026&month=10')->assertOk()
            ->assertJsonPath('data.rewards_summary.total_redemptions', 2);
        foreach ([$response, $report] as $result) {
            $this->assertStringNotContainsString($code, $result->getContent());
            $this->assertStringNotContainsString($pending->fresh()->claim_code_digest, $result->getContent());
            $this->assertStringNotContainsString('claim_code_encrypted', $result->getContent());
        }
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('overview.rewards_summary.total_redemptions', 1);
        $this->assertNull($legacy->fresh()->claimed_at);

    }

    public static function databaseFailures(): array
    {
        return [['activate'], ['verify'], ['confirm']];
    }

    #[DataProvider('databaseFailures')]
    public function test_database_errors_do_not_surface_secret_query_bindings_or_log_codes(string $action): void
    {
        $voucher = $this->purchase();
        $code = $this->activate($voucher);
        $before = $this->history();
        $digest = $voucher->fresh()->claim_code_digest;
        if ($action === 'activate') {
            $voucher = $this->purchase($voucher->user);
            $before = $this->history();
        } else {
            $this->signIn(User::factory()->create(['role' => 'admin']));
        }
        DB::listen(function (QueryExecuted $query) use ($code): void {
            if (str_contains($query->sql, 'claim_code_digest')) {
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \PDOException('Injected secret: '.$code));
            }
        });
        Log::spy();

        $response = $action === 'activate'
            ? $this->postJson('/api/vouchers/'.$voucher->id.'/activate')
            : $this->postJson('/api/admin/rewards/claims/'.$action, ['claim_code' => $code]);

        $response->assertStatus(503);
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString($digest, $response->getContent());
        $this->assertSame($before, $this->history());
        Log::shouldNotHaveReceived('error');
    }
}
