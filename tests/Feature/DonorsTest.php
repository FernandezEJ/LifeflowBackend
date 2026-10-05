<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\DonorService;
use App\Services\DonorAchievementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        config(['app.calendar_timezone' => 'Asia/Manila']);
        $this->travelTo(now()->startOfSecond());
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
    public function test_both_roles_can_read_real_donors(string $role): void
    {
        $this->signIn(['role' => $role]);
        $donor = User::factory()->create(['name' => 'Real Donor', 'created_at' => '2026-09-03 17:30:00']);
        $donor->donorProfile()->create(['first_name' => 'Real', 'last_name' => 'Donor',
            'mobile_number' => '09123456789', 'birth_date' => '1990-01-01', 'gender' => 'Male', 'blood_type' => 'O+']);
        $this->assessment($donor, 'eligible');

        $response = $this->getJson('/api/admin/donors')->assertOk()->assertJson([
            'summary' => ['totalDonors' => 1, 'eligible' => 1, 'temporarilyIneligible' => 0],
            'donors' => [['id' => $donor->id, 'name' => 'Real Donor', 'bloodType' => 'O+',
                'eligibility' => 'eligible', 'totalDonations' => 0, 'achievement' => 'New Donor', 'joinedDate' => '2026-09-04']],
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertJsonPath('topDonors', $response->json('donors'))
            ->assertJsonPath('bloodTypes', ['O+'])
            ->assertJsonPath('pagination', ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 1]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_guest_cannot_read_donors(): void
    {
        $this->getJson('/api/admin/donors')->assertUnauthorized();
    }

    public static function blockedAccounts(): array
    {
        return ['donor' => [['role' => 'donor']], 'password change' => [['must_change_password' => true]],
            'deactivated admin' => [['deactivated_at' => '2026-09-01']]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_existing_admin_guards_block_ineligible_accounts(array $attributes): void
    {
        $this->signIn($attributes);
        $this->getJson('/api/admin/donors')->assertForbidden();
    }

    public function test_only_active_donors_are_listed_with_descending_id_ties(): void
    {
        $this->signIn();
        User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['deactivated_at' => now()]);
        User::factory()->create(['deleted_at' => now()]);
        $first = User::factory()->create(['name' => 'Zulu']);
        $second = User::factory()->create(['name' => 'Alpha']);
        foreach ([$first, $second] as $donor) {
            DB::table('users')->where('id', $donor->id)->update(['created_at' => null]);
        }

        $expected = ['summary' => ['totalDonors' => 2, 'eligible' => 0, 'temporarilyIneligible' => 0],
            'donors' => array_map(fn (User $donor): array => ['id' => $donor->id, 'name' => $donor->name,
                'bloodType' => null, 'eligibility' => 'unassessed', 'totalDonations' => 0,
                'achievement' => 'New Donor', 'joinedDate' => null], [$second, $first])];
        $expected['topDonors'] = array_reverse($expected['donors']);
        $expected['bloodTypes'] = ['Unknown'];
        $expected['pagination'] = ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 2];
        $this->getJson('/api/admin/donors')->assertOk()->assertExactJson($expected);
        $this->getJson('/api/admin/donors')->assertOk()->assertExactJson($expected);
    }

    public function test_empty_system_has_zero_summary_and_no_fake_rows(): void
    {
        $this->signIn();
        $this->getJson('/api/admin/donors')->assertOk()->assertExactJson([
            'summary' => ['totalDonors' => 0, 'eligible' => 0, 'temporarilyIneligible' => 0], 'donors' => [],
            'topDonors' => [], 'bloodTypes' => [],
            'pagination' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 0],
        ]);
    }

    public function test_latest_assessment_and_ties_match_dashboard_summary(): void
    {
        $this->signIn();
        $eligible = User::factory()->create();
        $this->assessment($eligible, 'not_eligible', '2026-01-01');
        $this->assessment($eligible, 'eligible', '2026-02-01');
        $this->assessment($eligible, 'not_eligible', '2025-12-01');
        $ineligible = User::factory()->create();
        $this->assessment($ineligible, 'eligible', '2026-02-01');
        $this->assessment($ineligible, 'not_eligible', '2026-02-01');
        $legacy = User::factory()->create();
        $this->assessment($legacy, 'temporarily_ineligible');
        $indeterminate = User::factory()->create();
        $this->assessment($indeterminate, 'needs_further_screening');
        User::factory()->create();

        $this->getJson('/api/admin/donors')->assertOk()
            ->assertJsonPath('summary', ['totalDonors' => 5, 'eligible' => 1, 'temporarilyIneligible' => 2])
            ->assertJsonPath('donors.4.eligibility', 'eligible')
            ->assertJsonPath('donors.3.eligibility', 'temporarily_ineligible')
            ->assertJsonPath('donors.2.eligibility', 'temporarily_ineligible')
            ->assertJsonPath('donors.1.eligibility', 'indeterminate')
            ->assertJsonPath('donors.0.eligibility', 'unassessed');
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('stats', ['donors' => 5, 'eligible' => 1, 'not_eligible' => 2]);
    }

    public function test_completed_counts_deduplicate_legacy_records_and_exclude_untrusted_states(): void
    {
        $this->signIn();
        $donor = User::factory()->create();
        $other = User::factory()->create();
        $completed = $this->participation($donor, 'completed');
        $this->record($donor, 'completed', $completed);
        $this->record($donor, 'completed');
        $pending = $this->participation($donor, 'pending');
        $this->record($donor, 'completed', $pending);
        foreach (['pending', 'for_verification', 'needs_revision', 'rejected', 'cancelled'] as $status) {
            $this->participation($donor, $status);
        }
        $this->record($donor, 'pending');
        $this->record($donor, 'rejected');
        $otherCompleted = $this->participation($other, 'completed');
        $this->record($other, 'completed', $otherCompleted);

        $this->getJson('/api/admin/donors')->assertOk()->assertJsonPath('donors.1.totalDonations', 3)
            ->assertJsonPath('donors.1.achievement', 'Bronze Donor')
            ->assertJsonPath('donors.0.totalDonations', 1)->assertJsonPath('donors.0.achievement', 'First-Time Donor')
            ->assertJsonPath('topDonors.0.id', $donor->id)->assertJsonPath('topDonors.0.totalDonations', 3);
        $this->assertSame(3, app(DonorAchievementService::class)->summary($donor)['total_donations']);
        $this->assertSame(1, app(DonorAchievementService::class)->summary($other)['total_donations']);
    }

    public function test_legacy_deduplication_keeps_the_existing_same_owner_semantics_in_a_batch(): void
    {
        $this->signIn();
        $owner = User::factory()->create();
        $legacyOwner = User::factory()->create();
        $foreignCompleted = $this->participation($owner, 'completed');
        $this->record($legacyOwner, 'completed', $foreignCompleted);

        $this->getJson('/api/admin/donors')->assertOk()
            ->assertJsonPath('donors.0.totalDonations', 1)->assertJsonPath('donors.1.totalDonations', 1);
        $this->assertSame(1, app(DonorAchievementService::class)->summary($legacyOwner)['total_donations']);
    }

    public function test_all_canonical_achievements_are_exposed_from_completed_counts(): void
    {
        $this->signIn();
        $expected = [0 => 'New Donor', 1 => 'First-Time Donor', 3 => 'Bronze Donor', 5 => 'Silver Donor', 10 => 'Gold Donor'];
        foreach ($expected as $count => $label) {
            $donor = User::factory()->create();
            for ($index = 0; $index < $count; $index++) {
                $this->participation($donor, 'completed');
            }
        }

        $rows = $this->getJson('/api/admin/donors')->assertOk()->json('donors');
        $this->assertSame(array_reverse(array_keys($expected)), array_column($rows, 'totalDonations'));
        $this->assertSame(array_reverse(array_values($expected)), array_column($rows, 'achievement'));
    }

    public function test_response_allowlist_and_get_only_access_do_not_mutate_donor_data(): void
    {
        $this->signIn();
        $donor = User::factory()->create(['email' => 'private@example.test']);
        $this->assessment($donor, 'not_eligible');
        $this->participation($donor, 'completed');
        $this->record($donor, 'completed');
        $tables = ['users', 'donor_profiles', 'eligibility_assessments', 'donation_participations',
            'donation_records', 'audit_logs', 'point_transactions', 'user_vouchers', 'notifications'];
        $before = array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(), $tables);

        $response = $this->getJson('/api/admin/donors')->assertOk();
        $this->assertEqualsCanonicalizing(['summary', 'donors', 'topDonors', 'bloodTypes', 'pagination'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['id', 'name', 'bloodType', 'eligibility', 'totalDonations', 'achievement', 'joinedDate'],
            array_keys($response->json('donors.0')));
        $this->assertSame(array_keys($response->json('donors.0')), array_keys($response->json('topDonors.0')));
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertStringNotContainsString('private/proof.jpg', $response->getContent());
        $this->assertStringNotContainsString('Private assessment answer', $response->getContent());
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/admin/donors', ['id' => $donor->id, 'name' => 'Changed', 'eligibility' => 'eligible'])->assertStatus(405);
        }
        $this->getJson('/api/admin/donors/'.$donor->id)->assertNotFound();
        $after = array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(), $tables);
        $this->assertEquals($before, $after);
    }

    public function test_query_count_does_not_grow_per_donor(): void
    {
        User::factory()->create();
        DB::enableQueryLog();
        app(DonorService::class)->listing();
        $singleCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        foreach (User::factory()->count(20)->create() as $donor) {
            $this->assessment($donor, 'eligible');
            $this->participation($donor, 'completed');
        }

        DB::enableQueryLog();
        $result = app(DonorService::class)->listing();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(21, $result['summary']['totalDonors']);
        $this->assertSame(20, $result['summary']['eligible']);
        $this->assertSame($singleCount, count($queries));
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertCount(20, $result['donors']);
        $this->assertCount(10, $result['topDonors']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_default_pages_are_bounded_and_new_registrations_lead_with_stable_timestamp_ties(): void
    {
        $this->signIn();
        $old = User::factory()->create(['created_at' => '2025-01-01']);
        $tied = User::factory()->count(41)->create(['created_at' => '2026-01-01']);
        $ids = array_reverse($tied->modelKeys());
        $first = $this->getJson('/api/admin/donors')->assertOk()->assertJsonCount(20, 'donors')
            ->assertJsonPath('pagination', ['currentPage' => 1, 'lastPage' => 3, 'perPage' => 20, 'total' => 42]);
        $this->assertSame(array_slice($ids, 0, 20), array_column($first->json('donors'), 'id'));
        $second = $this->getJson('/api/admin/donors?page=2')->assertOk()->assertJsonCount(20, 'donors')
            ->assertJsonPath('pagination.currentPage', 2);
        $this->assertSame(array_slice($ids, 20, 20), array_column($second->json('donors'), 'id'));
        $last = $this->getJson('/api/admin/donors?page=3')->assertOk()->assertJsonCount(2, 'donors');
        $this->assertSame([$ids[40], $old->id], array_column($last->json('donors'), 'id'));
        $new = User::factory()->create(['created_at' => '2026-10-04']);
        $this->getJson('/api/admin/donors')->assertOk()->assertJsonPath('donors.0.id', $new->id)
            ->assertJsonPath('summary.totalDonors', 43)->assertJsonPath('pagination.total', 43);
        $this->getJson('/api/admin/donors?page=4')->assertOk()->assertJsonCount(0, 'donors')
            ->assertJsonPath('pagination.total', 43)->assertJsonPath('pagination.lastPage', 3);
        $this->getJson('/api/admin/donors?page=2&per_page=1')->assertOk()->assertJsonCount(1, 'donors')
            ->assertJsonPath('donors.0.id', $ids[0])
            ->assertJsonPath('pagination', ['currentPage' => 2, 'lastPage' => 43, 'perPage' => 1, 'total' => 43]);
    }

    public function test_search_and_blood_filters_apply_before_pagination_without_shrinking_summary_or_options(): void
    {
        $this->signIn();
        $matches = User::factory()->count(25)->create(['name' => 'Target Donor', 'created_at' => '2025-01-01']);
        foreach ($matches as $donor) {
            $donor->donorProfile()->create(['first_name' => 'Target', 'last_name' => 'Donor',
                'mobile_number' => sprintf('09%09d', $donor->id), 'birth_date' => '1990-01-01', 'gender' => 'Male', 'blood_type' => 'A-']);
            $this->assessment($donor, 'eligible');
        }
        User::factory()->count(22)->create(['name' => 'Other', 'created_at' => '2026-01-01']);
        $ids = array_reverse($matches->modelKeys());
        foreach (['search= tArGeT ', 'blood_type=A-', 'search=a-', 'search=target&blood_type=A-&eligibility=eligible'] as $query) {
            $response = $this->getJson('/api/admin/donors?page=2&'.str_replace(' ', '%20', $query))->assertOk()
                ->assertJsonCount(5, 'donors')
                ->assertJsonPath('pagination', ['currentPage' => 2, 'lastPage' => 2, 'perPage' => 20, 'total' => 25])
                ->assertJsonPath('summary', ['totalDonors' => 47, 'eligible' => 25, 'temporarilyIneligible' => 0])
                ->assertJsonPath('bloodTypes', ['A-', 'Unknown']);
            $this->assertSame(array_slice($ids, 20), array_column($response->json('donors'), 'id'));
        }
        $this->getJson('/api/admin/donors?page=2&blood_type=Unknown')->assertOk()->assertJsonCount(2, 'donors')
            ->assertJsonPath('pagination.total', 22);
        $this->getJson('/api/admin/donors?search=no-match')->assertOk()->assertJsonCount(0, 'donors')
            ->assertJsonPath('pagination.total', 0)->assertJsonPath('summary.totalDonors', 47)
            ->assertJsonPath('bloodTypes', ['A-', 'Unknown'])->assertJsonCount(10, 'topDonors');
    }

    public function test_search_treats_sql_wildcards_literally(): void
    {
        $this->signIn();
        $literal = User::factory()->create(['name' => '100%_! Donor']);
        User::factory()->create(['name' => '100xyz Donor']);
        $this->getJson('/api/admin/donors?search=%25_%21')->assertOk()->assertJsonCount(1, 'donors')
            ->assertJsonPath('donors.0.id', $literal->id)->assertJsonPath('pagination.total', 1);
    }

    public static function eligibilityFilters(): array
    {
        return [['eligible', 'eligible'], ['temporarily_ineligible', 'not_eligible'],
            ['temporarily_ineligible', 'temporarily_ineligible'], ['unassessed', null], ['indeterminate', 'needs_further_screening']];
    }

    #[DataProvider('eligibilityFilters')]
    public function test_eligibility_filter_uses_latest_assessment_before_pagination(string $filter, ?string $result): void
    {
        $this->signIn();
        $matches = User::factory()->count(23)->create(['created_at' => '2025-01-01']);
        if ($result !== null) {
            foreach ($matches as $donor) {
                $this->assessment($donor, $result === 'eligible' ? 'not_eligible' : 'eligible', '2026-01-01');
                $this->assessment($donor, $result, '2026-02-01');
            }
        }
        foreach (User::factory()->count(22)->create(['created_at' => '2026-01-01']) as $donor) {
            $this->assessment($donor, $result === 'eligible' ? 'not_eligible' : 'eligible');
        }
        $response = $this->getJson('/api/admin/donors?page=2&eligibility='.$filter)->assertOk()
            ->assertJsonCount(3, 'donors')->assertJsonPath('pagination.total', 23)
            ->assertJsonPath('pagination.lastPage', 2)->assertJsonPath('summary.totalDonors', 45);
        $this->assertSame(array_slice(array_reverse($matches->modelKeys()), 20), array_column($response->json('donors'), 'id'));
        $this->assertSame([$filter, $filter, $filter], array_column($response->json('donors'), 'eligibility'));
    }

    public function test_top_ten_is_global_and_keeps_completed_count_then_ascending_id_ranking(): void
    {
        $this->signIn();
        $ranked = User::factory()->count(12)->create(['name' => 'Leaderboard', 'created_at' => '2025-01-01']);
        foreach ($ranked as $index => $donor) {
            for ($count = 0; $count < intdiv($index, 2) + 1; $count++) {
                $completed = $this->participation($donor, 'completed');
                $this->record($donor, 'completed', $completed);
            }
            $this->participation($donor, 'rejected');
        }
        $newer = User::factory()->count(25)->create(['name' => 'Newer', 'created_at' => '2026-01-01']);
        $expected = [];
        for ($index = 10; $index >= 2; $index -= 2) {
            $expected[] = $ranked[$index]->id;
            $expected[] = $ranked[$index + 1]->id;
        }
        foreach (['', '?page=2', '?search=Newer&eligibility=unassessed&blood_type=Unknown', '?search=absent'] as $query) {
            $response = $this->getJson('/api/admin/donors'.$query)->assertOk()->assertJsonCount(10, 'topDonors');
            $this->assertSame($expected, array_column($response->json('topDonors'), 'id'));
            $this->assertSame([6, 6, 5, 5, 4, 4, 3, 3, 2, 2], array_column($response->json('topDonors'), 'totalDonations'));
        }
        $this->getJson('/api/admin/donors')->assertOk()->assertJsonPath('donors.0.id', $newer->last()->id);
    }

    public static function invalidFilters(): array
    {
        return [['per_page=21', 'per_page'], ['per_page=0', 'per_page'], ['per_page=1.5', 'per_page'],
            ['page=0', 'page'], ['page=abc', 'page'], ['page=2147483648', 'page'],
            ['search[]=test', 'search'], ['blood_type=invalid', 'blood_type'], ['eligibility=pending', 'eligibility']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_and_unbounded_pages_are_rejected(string $query, string $field): void
    {
        $this->signIn();
        $this->getJson('/api/admin/donors?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    private function assessment(User $donor, string $result, string $date = '2026-09-01'): void
    {
        $donor->eligibilityAssessments()->create(['result' => $result,
            'reasons' => ['Private assessment reason'], 'answers' => ['Private assessment answer'], 'assessed_at' => $date]);
    }

    /** Trusted historical fixtures bypass write-side points and notification observers. */
    private function participation(User $donor, string $status): int
    {
        return DB::table('donation_participations')->insertGetId(['user_id' => $donor->id,
            'source_type' => 'red_cross_dagupan', 'status' => $status, 'joined_at' => now(),
            'verified_at' => $status === 'completed' ? now() : null, 'proof_path' => 'private/proof.jpg',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function record(User $donor, string $status, ?int $participationId = null): void
    {
        DB::table('donation_records')->insert(['user_id' => $donor->id, 'donation_date' => '2026-09-01',
            'location' => 'Center', 'status' => $status, 'donation_participation_id' => $participationId,
            'submitted_at' => now(), 'verified_at' => $status === 'completed' ? now() : null,
            'created_at' => now(), 'updated_at' => now()]);
    }
}
