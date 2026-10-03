<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-09-27 05:00:00');
        config(['app.calendar_timezone' => 'Asia/Manila']);
    }

    private function signIn(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'admin', ...$attributes]);
        $this->withToken($user->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_roles_receive_zero_filled_dashboard(string $role): void
    {
        $this->signIn(['role' => $role]);

        $response = $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('months', 6)->assertJsonPath('stats', ['donors' => 0, 'eligible' => 0, 'not_eligible' => 0])
            ->assertJsonCount(6, 'donation_activity')->assertJsonCount(8, 'blood_types')->assertJsonPath('recent_verifications', []);
        $this->assertSame([0, 0, 0, 0, 0, 0], array_column($response->json('donation_activity'), 'donations'));
        $this->assertSame($role === 'super_admin', array_key_exists('overview', $response->json()));
    }

    public function test_unauthenticated_request_is_401(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public static function blockedAccounts(): array
    {
        return ['donor' => [['role' => 'donor']], 'password change' => [['must_change_password' => true]],
            'deactivated' => [['deactivated_at' => '2026-09-26 00:00:00']]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_forbidden_accounts_are_403(array $attributes): void
    {
        $this->signIn($attributes);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_kpis_use_latest_saved_result_once_and_exclude_inactive_accounts(): void
    {
        $admin = $this->signIn();
        $eligible = User::factory()->create();
        $ineligible = User::factory()->create();
        User::factory()->create(); // Unassessed is neither eligible nor not eligible.
        $inactive = User::factory()->create(['deactivated_at' => now()]);
        $deleted = User::factory()->create(['deleted_at' => now()]);
        $super = User::factory()->create(['role' => 'super_admin']);
        foreach ([$eligible, $ineligible, $inactive, $deleted, $admin, $super] as $user) {
            $user->eligibilityAssessments()->create(['result' => 'not_eligible', 'reasons' => [], 'answers' => [], 'assessed_at' => '2026-01-01']);
        }
        $eligible->eligibilityAssessments()->create(['result' => 'eligible', 'reasons' => [], 'answers' => [], 'assessed_at' => '2026-02-01']);
        // A later ID with an older assessment timestamp must not win.
        $eligible->eligibilityAssessments()->create(['result' => 'not_eligible', 'reasons' => [], 'answers' => [], 'assessed_at' => '2025-12-01']);
        $ineligible->eligibilityAssessments()->create(['result' => 'eligible', 'reasons' => [], 'answers' => [], 'assessed_at' => '2026-02-01']);
        $ineligible->eligibilityAssessments()->create(['result' => 'not_eligible', 'reasons' => [], 'answers' => [], 'assessed_at' => '2026-02-01']);

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('stats', ['donors' => 3, 'eligible' => 1, 'not_eligible' => 1]);
    }

    private function participation(User $donor, string $status, ?string $verifiedAt, string $submitted = '2026-09-25 00:00:00'): int
    {
        // Historical fixtures bypass outcome observers; this endpoint is read-only.
        return DB::table('donation_participations')->insertGetId(['user_id' => $donor->id,
            'source_type' => 'red_cross_dagupan', 'status' => $status, 'verified_at' => $verifiedAt,
            'joined_at' => '2026-01-01', 'proof_uploaded_at' => $submitted, 'created_at' => $submitted, 'updated_at' => $submitted]);
    }

    public function test_legacy_assessments_preserve_not_eligible_and_indeterminate_semantics(): void
    {
        $this->signIn();
        foreach (['temporarily_ineligible', 'needs_further_screening'] as $result) {
            User::factory()->create()->eligibilityAssessments()->create([
                'result' => $result, 'answers' => [], 'reasons' => [], 'assessed_at' => '2025-01-01',
            ]);
        }

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('stats', ['donors' => 2, 'eligible' => 0, 'not_eligible' => 1]);
    }

    public function test_completed_activity_uses_local_month_boundaries_and_rolling_periods(): void
    {
        $admin = $this->signIn();
        $donor = User::factory()->create();
        foreach (['pending', 'for_verification', 'needs_revision', 'rejected', 'cancelled'] as $status) {
            $this->participation($donor, $status, '2026-04-15 00:00:00');
        }
        $this->participation($donor, 'completed', '2026-03-31 15:59:59'); // March in Manila.
        $linked = $this->participation($donor, 'completed', '2026-03-31 16:00:00'); // April in Manila.
        $this->participation($donor, 'completed', '2026-09-30 15:59:59');
        $this->participation($donor, 'completed', '2026-09-30 16:00:00'); // October excluded.
        $this->participation($donor, 'completed', null); // Never infer from updated_at.
        $this->participation($admin, 'completed', '2026-04-01 00:00:00');
        foreach ([$linked, null] as $participationId) {
            DB::table('donation_records')->insert(['user_id' => $donor->id, 'donation_participation_id' => $participationId,
                'donation_date' => '2026-01-01', 'location' => 'Dagupan', 'status' => 'completed',
                'submitted_at' => '2026-01-01', 'verified_at' => '2026-04-15 00:00:00']);
        }
        $donor->delete(); // Completed history remains despite later soft deletion.

        $six = $this->getJson('/api/admin/dashboard?months=6')->assertOk();
        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], array_column($six->json('donation_activity'), 'period'));
        $this->assertSame([2, 0, 0, 0, 0, 1], array_column($six->json('donation_activity'), 'donations'));
        $this->getJson('/api/admin/dashboard?months=12')->assertOk()->assertJsonCount(12, 'donation_activity')
            ->assertJsonPath('donation_activity.0.period', '2025-10')->assertJsonPath('donation_activity.5.donations', 1);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidPeriods(): array
    {
        return [['0'], ['7'], ['24'], ['6.5'], ['abc'], [''], ['6%20OR%201=1'], ['6&months[]=12']];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_is_422(string $period): void
    {
        $this->signIn();
        $this->getJson('/api/admin/dashboard?months='.$period)->assertUnprocessable()->assertJsonValidationErrors('months');
    }

    public function test_blood_type_distribution_counts_active_donor_profiles_only(): void
    {
        $admin = $this->signIn();
        $donors = [User::factory()->create(), User::factory()->create(), User::factory()->create(),
            $admin, User::factory()->create(['role' => 'super_admin']), User::factory()->create(['deleted_at' => now()]),
            User::factory()->create(['deactivated_at' => now()])];
        foreach ($donors as $index => $user) {
            $user->donorProfile()->create(['first_name' => 'Donor', 'last_name' => 'Test', 'mobile_number' => '0912345678'.$index,
                'birth_date' => '1990-01-01', 'gender' => 'Male', 'blood_type' => $index === 2 ? 'AB-' : 'O+']);
        }

        $types = $this->getJson('/api/admin/dashboard')->assertOk()->json('blood_types');
        $this->assertSame(['O+' => 2, 'A+' => 0, 'B+' => 0, 'AB+' => 0, 'O-' => 0, 'A-' => 0, 'B-' => 0, 'AB-' => 1], array_column($types, 'count', 'bloodType'));
    }

    public function test_recent_reviews_are_limited_ordered_and_navigable_without_private_proofs(): void
    {
        $this->signIn();
        $donor = User::factory()->create(['name' => 'Real Donor']);
        foreach (['pending', 'completed', 'rejected', 'cancelled'] as $status) {
            $this->participation($donor, $status, null, '2026-09-27 00:00:00');
        }
        $ids = [];
        for ($day = 20; $day <= 25; $day++) {
            $ids[] = $this->participation($donor, $day === 25 ? 'needs_revision' : 'for_verification', null, '2026-09-'.$day.' 00:00:00');
        }

        $response = $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonCount(5, 'recent_verifications')
            ->assertJsonPath('recent_verifications.0.status', 'needs_revision')->assertJsonPath('recent_verifications.0.donor', 'Real Donor');
        $this->assertSame(array_reverse(array_slice($ids, 1)), array_column($response->json('recent_verifications'), 'id'));
        $this->assertArrayNotHasKey('proof_path', $response->json('recent_verifications.0'));
        $this->getJson('/api/admin/verifications/'.$ids[5])->assertOk()->assertJsonPath('verification.id', $ids[5]);
    }

    public function test_super_overview_uses_real_admin_totals_and_limited_activity(): void
    {
        $actor = $this->signIn(['role' => 'super_admin']);
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);
        User::factory()->create(['role' => 'admin', 'deleted_at' => now()]);
        foreach (['admin_created', 'admin_updated', 'admin_deactivated', 'admin_reactivated'] as $action) {
            $actor->auditLogs()->create(['actor_role' => 'super_admin', 'action' => $action, 'module' => 'admins']);
        }

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('overview.total_admins', 2)
            ->assertJsonPath('overview.active_admins', 1)->assertJsonCount(3, 'overview.recent_admin_activity')
            ->assertJsonPath('overview.recent_admin_activity.0.action_label', 'Admin reactivated')
            ->assertJsonPath('overview.rewards_summary.total_redemptions', 0);
    }

    public function test_existing_redemption_preview_is_read_only_and_uses_recorded_current_month(): void
    {
        $this->signIn(['role' => 'super_admin']);
        $donor = User::factory()->create();
        $reward = Reward::factory()->create(['name' => 'Recorded voucher']);
        foreach (['2026-08-31 15:59:59', '2026-08-31 16:00:00', '2026-09-20 00:00:00', '2026-09-30 16:00:00'] as $index => $date) {
            DB::table('user_vouchers')->insert(['user_id' => $donor->id, 'reward_id' => $reward->id,
                'points_spent' => 300, 'status' => 'redeemed', 'voucher_token' => 'private-'.$index,
                'redemption_key' => 'redemption-'.$index, 'redeemed_at' => $date]);
        }
        DB::table('user_vouchers')->insert(['user_id' => $donor->id, 'reward_id' => $reward->id,
            'points_spent' => 300, 'status' => 'active', 'voucher_token' => 'private-active',
            'redemption_key' => 'active', 'expires_at' => '2026-09-01 00:00:00']);

        $response = $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('overview.rewards_summary.total_redemptions', 2)
            ->assertJsonPath('overview.rewards_summary.most_redeemed_reward', 'Recorded voucher');
        $this->assertStringNotContainsString('private-', $response->getContent());
        $this->assertDatabaseHas('user_vouchers', ['voucher_token' => 'private-active', 'status' => 'active']);
    }
}
