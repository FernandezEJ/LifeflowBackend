<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAssessmentCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-11-07 04:00:00');
        config(['app.calendar_timezone' => 'Asia/Manila']);
    }

    private function signIn(array $attributes = []): User
    {
        $actor = User::factory()->create(['role' => 'super_admin', ...$attributes]);
        $this->withToken($actor->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $actor;
    }

    private function assessment(User $donor, string $result, ?string $version = null, string $date = '2026-11-01'): void
    {
        $donor->eligibilityAssessments()->create(['result' => $result, 'assessment_version' => $version,
            'assessed_at' => $date, 'answers' => ['follow_ups' => ['medications' => 'private-health-fixture']],
            'reasons' => ['private-reason-fixture']]);
    }

    private function population(): array
    {
        $rows = [];
        foreach (['basic_requirements_met', 'needs_further_review', 'eligible', 'not_eligible',
            'temporarily_ineligible', 'needs_further_screening', 'unassessed', 'indeterminate'] as $bucket) {
            $donor = User::factory()->create();
            $rows[$bucket] = $donor;
            if ($bucket === 'unassessed') {
                continue;
            }
            if (in_array($bucket, ['basic_requirements_met', 'needs_further_review'])) {
                $this->assessment($donor, 'eligible', null, '2026-10-01');
                $this->assessment($donor, $bucket, 'v5');
                $this->assessment($donor, 'not_eligible', null, '2026-09-01');
            } elseif ($bucket === 'indeterminate') {
                $this->assessment($donor, 'basic_requirements_met', 'future-version');
            } else {
                $this->assessment($donor, $bucket);
            }
        }
        $rows['tie'] = User::factory()->create();
        $this->assessment($rows['tie'], 'needs_further_review', 'v5');
        $this->assessment($rows['tie'], 'basic_requirements_met', 'v5');
        $rows['missing_version'] = User::factory()->create();
        $this->assessment($rows['missing_version'], 'needs_further_review');
        $rows['future_code'] = User::factory()->create();
        // A future result is synthetic only; production enum/schema are never changed.
        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->assessment($rows['future_code'], 'future_result', 'v5');
        DB::statement('PRAGMA ignore_check_constraints = OFF');
        foreach ([['deactivated_at' => now()], ['deleted_at' => now()], ['role' => 'admin']] as $attributes) {
            $this->assessment(User::factory()->create($attributes), 'basic_requirements_met', 'v5');
        }

        return $rows;
    }

    private function expectedCounts(): array
    {
        return ['basic_requirements_met' => 2, 'needs_further_review' => 1, 'eligible' => 1, 'not_eligible' => 1,
            'temporarily_ineligible' => 1, 'needs_further_screening' => 1, 'unassessed' => 1, 'indeterminate' => 3];
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_latest_per_current_donor_counts_agree_without_health_details_or_writes(string $role): void
    {
        $this->signIn(['role' => $role]);
        $rows = $this->population();
        $before = DB::table('eligibility_assessments')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        $donors = $this->getJson('/api/admin/donors')->assertOk()->assertJsonPath('assessment_counts', $this->expectedCounts())
            ->assertJsonPath('summary', ['totalDonors' => 11, 'eligible' => 1, 'temporarilyIneligible' => 2]);
        $dashboard = $this->getJson('/api/admin/dashboard?months=12')->assertOk()
            ->assertJsonPath('stats', ['donors' => 11, 'eligible' => 1, 'not_eligible' => 2])
            ->assertJsonPath('assessment_counts', $this->expectedCounts())->assertJsonCount(12, 'donation_activity');
        $responses = [$donors, $dashboard];
        if ($role === 'super_admin') {
            foreach (['2026&month=11', '2025&month=1'] as $period) {
                $responses[] = $this->getJson('/api/admin/system-reports?year='.$period)->assertOk()
                    ->assertJsonPath('data.assessment_counts', $this->expectedCounts())
                    ->assertJsonPath('data.donor_summary', ['total' => 11, 'eligible' => 1, 'not_eligible' => 2]);
            }
        } else {
            $this->getJson('/api/admin/system-reports')->assertForbidden();
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_starts_with($query['query'], 'update "personal_access_tokens" set "last_used_at"')) {
                continue;
            }
            $this->assertStringStartsWith('select', strtolower($query['query']));
            if (str_contains($query['query'], 'eligibility_assessments')) {
                $this->assertStringNotContainsString('answers', $query['query']);
                $this->assertStringNotContainsString('reasons', $query['query']);
            }
        }
        foreach ($responses as $response) {
            foreach (['private-health-fixture', 'private-reason-fixture', 'follow_ups', 'medications', 'answers', 'reasons'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $byId = collect($donors->json('donors'))->keyBy('id');
        foreach (['basic_requirements_met', 'needs_further_review'] as $result) {
            $this->assertSame($result, $byId[$rows[$result]->id]['eligibility']);
            $this->assertSame(['result' => $result, 'version' => 'v5'], $byId[$rows[$result]->id]['assessment']);
        }
        foreach (['eligible', 'not_eligible', 'temporarily_ineligible', 'needs_further_screening'] as $result) {
            $this->assertSame(['result' => $result, 'version' => null], $byId[$rows[$result]->id]['assessment']);
        }
        $this->assertSame(11, array_sum($donors->json('assessment_counts')));
        $this->assertSame($before, DB::table('eligibility_assessments')->orderBy('id')->get()->toJson());
    }

    public function test_filters_use_the_same_latest_buckets_before_pagination_and_keep_global_counts(): void
    {
        $this->signIn();
        $rows = $this->population();
        $filters = ['basic_requirements_met' => ['basic_requirements_met', 'tie'], 'needs_further_review' => ['needs_further_review'],
            'eligible' => ['eligible'], 'not_eligible' => ['not_eligible'],
            'temporarily_ineligible' => ['not_eligible', 'temporarily_ineligible'], 'needs_further_screening' => ['needs_further_screening'],
            'unassessed' => ['unassessed'], 'unknown' => ['indeterminate', 'missing_version', 'future_code'],
            'indeterminate' => ['needs_further_screening', 'indeterminate', 'missing_version', 'future_code']];
        foreach ($filters as $filter => $keys) {
            $response = $this->getJson('/api/admin/donors?per_page=1&eligibility='.$filter)->assertOk()
                ->assertJsonCount(1, 'donors')->assertJsonPath('pagination.total', count($keys))
                ->assertJsonPath('assessment_counts', $this->expectedCounts());
            $expectedIds = array_map(fn (string $key): int => $rows[$key]->id, $keys);
            rsort($expectedIds);
            $response->assertJsonPath('donors.0.id', $expectedIds[0]);
            if (count($keys) > 1) {
                $this->getJson('/api/admin/donors?per_page=1&page=2&eligibility='.$filter)->assertOk()
                    ->assertJsonPath('donors.0.id', $expectedIds[1])->assertJsonPath('assessment_counts', $this->expectedCounts());
            }
        }
        $this->getJson('/api/admin/donors?eligibility=unknown-future-filter')->assertUnprocessable();
    }

    public static function blockedAccounts(): array
    {
        return [[['role' => 'donor']], [['deactivated_at' => '2026-11-01']], [['must_change_password' => true]]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_assessment_read_permissions_remain_unchanged(array $attributes): void
    {
        $this->signIn($attributes);
        foreach (['donors', 'dashboard', 'system-reports'] as $path) {
            $this->getJson('/api/admin/'.$path)->assertForbidden();
        }
    }

    public function test_guest_cannot_read_assessment_counts(): void
    {
        foreach (['donors', 'dashboard', 'system-reports'] as $path) {
            $this->getJson('/api/admin/'.$path)->assertUnauthorized();
        }
    }
}
