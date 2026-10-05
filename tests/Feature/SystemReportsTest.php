<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\User;
use App\Services\Admin\RewardAnalyticsService;
use App\Services\DonorAchievementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-04 05:00:00');
        config(['app.calendar_timezone' => 'Asia/Manila']);
    }

    private function signIn(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'super_admin', ...$attributes]);
        $this->withToken($user->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    private function donor(array $attributes = []): User
    {
        return User::factory()->create(['created_at' => '2026-09-01 00:00:00', ...$attributes]);
    }

    private function assessment(User $donor, string $result, string $date): void
    {
        $donor->eligibilityAssessments()->create(['result' => $result, 'assessed_at' => $date,
            'answers' => ['private' => 'secret-assessment'], 'reasons' => ['secret-reason']]);
    }

    private function participation(User $donor, string $status, ?string $verified = '2026-09-10 00:00:00', string $joined = '2026-09-01 00:00:00'): int
    {
        // Trusted historical fixtures bypass outcome observers; reporting must never award points.
        return DB::table('donation_participations')->insertGetId(['user_id' => $donor->id, 'source_type' => 'red_cross_dagupan',
            'status' => $status, 'verified_at' => $verified, 'joined_at' => $joined, 'proof_path' => 'secret-proof-path',
            'proof_uploaded_at' => $joined, 'created_at' => $joined, 'updated_at' => $joined]);
    }

    private function legacy(User $donor, ?int $participation = null, ?string $verified = '2026-09-10 00:00:00', string $status = 'completed'): void
    {
        DB::table('donation_records')->insert(['user_id' => $donor->id, 'donation_participation_id' => $participation,
            'status' => $status, 'verified_at' => $verified, 'donation_date' => '2026-08-01', 'location' => 'Legacy facility',
            'submitted_at' => '2026-08-01', 'created_at' => now(), 'updated_at' => now(), 'notes' => 'secret-record-notes']);
    }

    private function purchase(User $donor, Reward $reward, string $date = '2026-09-10 00:00:00', string $status = 'available', array $ledger = []): int
    {
        $id = DB::table('user_vouchers')->insertGetId(['user_id' => $donor->id, 'reward_id' => $reward->id, 'points_spent' => 100,
            'status' => $status, 'voucher_token' => Str::random(64), 'redemption_key' => (string) Str::uuid(),
            'reward_snapshot' => json_encode(['name' => 'Owned '.$reward->name, 'voucher_value' => 100, 'image_url' => 'secret-image']),
            'created_at' => $date, 'updated_at' => $date]);
        DB::table('point_transactions')->insert(['user_id' => $donor->id, 'source_type' => 'user_voucher', 'source_id' => $id,
            'type' => 'reward_redemption', 'amount' => -100, 'event_key' => 'reward_redemption:'.$id,
            'created_at' => $date, 'updated_at' => $date, ...$ledger]);

        return $id;
    }

    private function report(array $period = ['year' => 2026, 'month' => 9]): array
    {
        return $this->getJson('/api/admin/system-reports?'.http_build_query($period))->assertOk()->json();
    }

    public function test_guest_cannot_read_reports(): void
    {
        $this->getJson('/api/admin/system-reports')->assertUnauthorized();
    }

    public static function blockedAccounts(): array
    {
        return ['donor' => [['role' => 'donor']], 'admin' => [['role' => 'admin']],
            'initial password' => [['must_change_password' => true]], 'deactivated' => [['deactivated_at' => '2026-10-01']]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_only_active_super_admin_with_changed_password_can_read(array $attributes): void
    {
        $this->signIn($attributes);
        $this->getJson('/api/admin/system-reports')->assertForbidden();
    }

    public static function invalidPeriods(): array
    {
        return [['year', 'bad'], ['year', '2026.5'], ['year', 1999], ['year', 2028], ['year', ['2026']], ['year', ''],
            ['month', 0], ['month', 13], ['month', '9.5'], ['month', 'September'], ['month', ['9']], ['month', '']];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_is_rejected(string $field, mixed $value): void
    {
        $this->signIn();
        $this->getJson('/api/admin/system-reports?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_default_and_padded_periods_return_zero_filled_printable_structure(): void
    {
        $this->signIn();
        $report = $this->report([]);
        $this->assertSame('2026-10', $report['period']);
        $this->assertSame(now()->toISOString(), $report['generated_at']);
        $data = $report['data'];
        $this->assertSame(['total' => 0, 'eligible' => 0, 'not_eligible' => 0], $data['donor_summary']);
        $this->assertSame(['total' => 0, 'completed' => 0, 'for_verification' => 0, 'needs_revision' => 0, 'rejected' => 0], $data['donation_summary']);
        $this->assertSame(['points_issued' => 0, 'most_redeemed_reward' => null, 'total_redemptions' => 0, 'points_redeemed' => 0], $data['rewards_summary']);
        $this->assertCount(8, $data['blood_types']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($data['weekly_donations'], 'donations'));
        $this->assertSame([], $data['completed_donations']);
        $this->assertSame([], $data['reward_redemptions']);
        $this->assertSame('2025-02', $this->report(['year' => '2025', 'month' => '02'])['period']);
    }

    public function test_current_donor_population_and_latest_assessments_are_independent_of_report_period(): void
    {
        $this->signIn();
        $eligible = $this->donor();
        $ineligible = $this->donor();
        $legacy = $this->donor();
        $screening = $this->donor();
        $this->donor(); // Unassessed belongs to neither outcome bucket.
        $this->assessment($eligible, 'not_eligible', '2026-09-01');
        $this->assessment($eligible, 'eligible', '2026-09-25');
        $this->assessment($eligible, 'not_eligible', '2026-09-10'); // Higher ID but older date.
        $this->assessment($eligible, 'not_eligible', '2026-09-30 16:00:00'); // October in Manila.
        $this->assessment($ineligible, 'eligible', '2026-09-20');
        $this->assessment($ineligible, 'not_eligible', '2026-09-20'); // ID breaks timestamp ties.
        $this->assessment($legacy, 'temporarily_ineligible', '2026-09-01');
        $this->assessment($screening, 'needs_further_screening', '2026-09-01');
        foreach ([['role' => 'admin'], ['role' => 'super_admin'], ['deactivated_at' => now()], ['deleted_at' => now()],
            ['created_at' => '2026-09-30 16:00:00']] as $attributes) {
            $this->assessment($this->donor($attributes), 'eligible', '2026-09-01');
        }
        $this->donor(['created_at' => null]);
        $expected = ['total' => 7, 'eligible' => 1, 'not_eligible' => 3];
        $this->assertSame($expected, $this->report()['data']['donor_summary']);
        $this->assertSame($expected, $this->report(['year' => 2026, 'month' => 8])['data']['donor_summary']);
        $this->assertSame($expected, $this->report(['year' => 2025, 'month' => 1])['data']['donor_summary']);
        $this->getJson('/api/admin/donors')->assertOk()->assertJsonPath('summary',
            ['totalDonors' => 7, 'eligible' => 1, 'temporarilyIneligible' => 3]);
    }

    public function test_blood_distribution_counts_events_for_all_eight_types_and_omits_unknown_profiles(): void
    {
        $this->signIn();
        $types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        foreach ($types as $type) {
            $donor = $this->donor();
            $this->profile($donor, $type);
            $this->participation($donor, 'completed');
        }
        $this->participation($this->donor(), 'completed'); // Unknown blood type is not invented.
        $this->profile($this->donor(), 'A+'); // Registration alone is not a donation event.
        $inactive = $this->donor(['deactivated_at' => now()]);
        $this->profile($inactive, 'A+');
        $this->participation($inactive, 'completed'); // Trusted past events survive account deactivation.
        $this->profile($this->donor(['created_at' => '2026-10-01']), 'O+');
        $data = $this->report()['data'];
        $this->assertSame($types, array_column($data['blood_types'], 'name'));
        $this->assertSame([2, 1, 1, 1, 1, 1, 1, 1], array_column($data['blood_types'], 'value'));
        $this->assertSame(10, $data['donation_summary']['completed']);
        $this->assertSame(11, $data['donor_summary']['total']);
        $emptyPeriod = $this->report(['year' => 2026, 'month' => 8])['data'];
        $this->assertSame(array_fill(0, 8, 0), array_column($emptyPeriod['blood_types'], 'value'));
        $this->assertSame(11, $emptyPeriod['donor_summary']['total']);
    }

    private function profile(User $donor, string $type): void
    {
        $donor->donorProfile()->create(['first_name' => 'Real', 'last_name' => 'Donor', 'blood_type' => $type,
            'mobile_number' => '09'.str_pad((string) $donor->id, 9, '0', STR_PAD_LEFT), 'birth_date' => '1990-01-01', 'gender' => 'Male']);
    }

    public function test_trusted_completions_and_same_owner_legacy_deduplication_match_achievements(): void
    {
        $this->signIn();
        $donor = $this->donor();
        $completed = $this->participation($donor, 'completed');
        $this->profile($donor, 'O+');
        $this->legacy($donor, $completed);
        $this->legacy($donor);
        foreach (['pending', 'for_verification', 'needs_revision', 'rejected', 'cancelled'] as $status) {
            $id = $this->participation($donor, $status);
            if ($status === 'pending') {
                $this->legacy($donor, $id); // A completed trusted legacy outcome still counts.
            }
        }
        $other = $this->donor();
        $this->profile($other, 'A-');
        $foreign = $this->participation($other, 'completed');
        $this->legacy($donor, $foreign); // Foreign-owner links do not deduplicate this donor's history.
        $this->legacy($donor, null, '2026-09-10', 'pending');
        $this->legacy($donor, null, '2026-09-10', 'rejected');
        $data = $this->report()['data'];
        $this->assertSame(5, $data['donation_summary']['completed']);
        $this->assertSame(7, $data['donation_summary']['total']);
        foreach (['for_verification', 'needs_revision', 'rejected'] as $status) {
            $this->assertSame(1, $data['donation_summary'][$status]);
        }
        $this->assertArrayNotHasKey('pending', $data['donation_summary']);
        $canonical = app(DonorAchievementService::class)->summaries([$donor->id, $other->id]);
        $this->assertSame(array_sum(array_column($canonical, 'total_donations')), $data['donation_summary']['completed']);
        $this->assertSame(5, array_sum(array_column($data['weekly_donations'], 'donations')));
        $this->assertCount(5, $data['completed_donations']);
        $this->assertSame([0, 1, 0, 0, 0, 0, 4, 0], array_column($data['blood_types'], 'value'));
    }

    public function test_completion_dates_use_manila_week_month_and_year_boundaries_and_preserve_inactive_history(): void
    {
        $this->signIn();
        $donor = $this->donor(['deleted_at' => now(), 'deactivated_at' => now()]);
        $this->profile($donor, 'O+');
        foreach (['2026-08-31 15:59:59', '2026-08-31 16:00:00', '2026-09-07 15:59:59', '2026-09-07 16:00:00',
            '2026-09-14 16:00:00', '2026-09-21 16:00:00', '2026-09-28 16:00:00', '2026-09-30 15:59:59', '2026-09-30 16:00:00'] as $date) {
            $this->participation($donor, 'completed', $date, '2026-08-01');
        }
        $this->participation($donor, 'completed', null);
        $this->legacy($donor, null, null);
        $outside = $this->participation($donor, 'completed', '2026-08-15');
        $this->legacy($donor, $outside); // Represented history stays deduplicated across periods.
        $this->participation($this->donor(['role' => 'admin']), 'completed');
        $data = $this->report()['data'];
        $this->assertSame([2, 1, 1, 1, 2], array_column($data['weekly_donations'], 'donations'));
        $this->assertSame(7, $data['donation_summary']['completed']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 7, 0], array_column($data['blood_types'], 'value'));
        $this->assertSame('2026-09-30', $data['completed_donations'][0]['date']);
        $this->assertSame('2026-09-01', $data['completed_donations'][6]['date']);
        $this->participation($donor, 'completed', '2026-12-31 16:00:00');
        $this->assertSame(1, $this->report(['year' => 2027, 'month' => 1])['data']['donation_summary']['completed']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 1, 0], array_column($this->report(['year' => 2027, 'month' => 1])['data']['blood_types'], 'value'));
        $this->assertSame(0, $this->report(['year' => 2026, 'month' => 2])['data']['weekly_donations'][4]['donations']);
    }

    public function test_participation_join_cohort_excludes_other_months_and_does_not_conflate_pending_with_review(): void
    {
        $this->signIn();
        $donor = $this->donor();
        foreach (['2026-08-31 15:59:59', '2026-08-31 16:00:00', '2026-09-30 15:59:59', '2026-09-30 16:00:00'] as $joined) {
            $this->participation($donor, 'for_verification', null, $joined);
        }
        $this->participation($donor, 'pending', null);
        $summary = $this->report()['data']['donation_summary'];
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['for_verification']);
        $this->assertSame(0, $summary['completed']);
    }

    public function test_rewards_use_matching_purchase_ledger_period_snapshot_and_canonical_top_reward(): void
    {
        $this->signIn();
        $donor = $this->donor(['deleted_at' => now()]);
        $other = $this->donor();
        $reward = Reward::create(['name' => 'Grocery', 'points_cost' => 999, 'stock_quantity' => 5]);
        $second = Reward::create(['name' => 'Travel', 'points_cost' => 200, 'stock_quantity' => 5]);
        foreach (['available', 'active', 'redeemed', 'expired'] as $status) {
            $this->purchase($donor, $reward, '2026-08-31 16:00:00', $status);
        }
        $this->purchase($donor, $second, '2026-09-30 15:59:59');
        $this->purchase($donor, $second, '2026-09-30 16:00:00');
        $this->purchase($donor, $second, '2026-08-31 15:59:59');
        $this->purchase($donor, $reward, ledger: ['user_id' => $other->id]);
        $this->purchase($donor, $reward, ledger: ['source_type' => 'other']);
        $this->purchase($donor, $reward, ledger: ['amount' => 100]);
        $this->purchase($donor, $reward, ledger: ['type' => 'donation_reward']);
        $missing = $this->purchase($donor, $reward);
        DB::table('point_transactions')->where('source_id', $missing)->where('source_type', 'user_voucher')->delete();
        DB::table('point_transactions')->insert(['user_id' => $donor->id, 'type' => 'donation_reward', 'amount' => 300,
            'event_key' => 'real-credit', 'created_at' => '2026-09-30 15:59:59', 'updated_at' => now()]);
        DB::table('point_transactions')->insert(['user_id' => $donor->id, 'type' => 'donation_reward', 'amount' => 700,
            'event_key' => 'next-credit', 'created_at' => '2026-09-30 16:00:00', 'updated_at' => now()]);
        $reward->update(['name' => 'Changed name', 'deleted_at' => now()]);
        $data = $this->report()['data'];
        $this->assertSame(['points_issued' => 400, 'most_redeemed_reward' => 'Owned Grocery', 'total_redemptions' => 5, 'points_redeemed' => 500], $data['rewards_summary']);
        $this->assertSame('Owned Travel', $data['reward_redemptions'][0]['voucher']);
        $this->assertSame('2026-09-30', $data['reward_redemptions'][0]['date']);
        $this->assertSame(100, $data['reward_redemptions'][0]['pointsUsed']);
        $analytics = app(RewardAnalyticsService::class)->summary(['year' => 2026, 'month' => 9]);
        $this->assertSame($analytics['summary']['total_redemptions'], $data['rewards_summary']['total_redemptions']);
        $this->assertSame($analytics['summary']['total_points_spent'], $data['rewards_summary']['points_redeemed']);
        $this->assertSame($analytics['top_rewards'][0]['reward_name'], $data['rewards_summary']['most_redeemed_reward']);
    }

    public function test_safe_named_rows_are_bounded_while_totals_remain_complete_and_reads_do_not_mutate_data(): void
    {
        $this->signIn();
        $donor = $this->donor(['name' => '<script>Real Donor</script>']);
        $this->profile($donor, 'O+');
        $this->assessment($donor, 'eligible', '2026-09-10');
        $reward = Reward::create(['name' => 'Grocery', 'points_cost' => 100, 'stock_quantity' => 500]);
        for ($index = 0; $index < 23; $index++) {
            $this->participation($donor, 'completed');
            $this->purchase($donor, $reward);
        }
        $tables = ['users', 'donor_profiles', 'eligibility_assessments', 'donation_participations', 'donation_records', 'rewards', 'user_vouchers', 'point_transactions', 'audit_logs'];
        $before = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        DB::enableQueryLog();
        $report = $this->report();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $data = $report['data'];
        $this->assertSame(23, $data['donation_summary']['completed']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 23, 0], array_column($data['blood_types'], 'value'));
        $this->assertSame(23, $data['rewards_summary']['total_redemptions']);
        $this->assertCount(20, $data['completed_donations']);
        $this->assertCount(20, $data['reward_redemptions']);
        $this->assertSame(['id', 'donor', 'bloodType', 'event', 'date'], array_keys($data['completed_donations'][0]));
        $this->assertSame(['id', 'donor', 'voucher', 'pointsUsed', 'date'], array_keys($data['reward_redemptions'][0]));
        $this->assertStringContainsString('up to 20 recent records', $data['notes']);
        $json = json_encode($report);
        foreach (['password', 'email', 'answers', 'proof_path', 'voucher_token', 'redemption_key', 'source_id', 'secret-assessment', 'secret-proof-path', 'secret-image', 'admins', 'audit_logs'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables));
        $this->assertLessThanOrEqual(18, $queryCount, 'Report queries do not scale per donor or transaction.');

    }
}
