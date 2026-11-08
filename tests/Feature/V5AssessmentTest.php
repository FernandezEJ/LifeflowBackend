<?php

namespace Tests\Feature;

use App\Models\DonationParticipation;
use App\Models\User;
use App\Services\Admin\DonorService;
use App\Services\DonationCooldown;
use App\Services\FlowieDonorContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class V5AssessmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public static function fields(): array
    {
        return [
            ['procedureType', 'procedurePast12Months'], ['procedureWhen', 'procedurePast12Months'],
            ['medications', 'medicationsOrSupplements'], ['transfusionWhen', 'everReceivedTransfusion'],
        ];
    }

    #[DataProvider('fields')]
    public function test_conditional_fields_are_required_bounded_trimmed_and_cleared(string $field, string $answer): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $payload = EligibilityEvaluatorTest::payload([$answer => 'YES']);
        foreach ([null, '', '   ', [], 123, str_repeat('x', 201), '<script>', "unsafe\ntext"] as $invalid) {
            $bad = $payload;
            $bad['follow_ups'][$field] = $invalid;
            $this->postJson('/api/eligibility-assessments', $bad)->assertUnprocessable()
                ->assertJsonValidationErrors('follow_ups.'.$field);
        }
        $bad = $payload;
        unset($bad['follow_ups'][$field]);
        $this->postJson('/api/eligibility-assessments', $bad)->assertUnprocessable();
        $this->assertDatabaseCount('eligibility_assessments', 0);
        $payload['follow_ups'][$field] = '  Approximate timing or name  ';
        $this->postJson('/api/eligibility-assessments', $payload)->assertCreated()
            ->assertJsonPath('assessment.assessment_version', 'v5')
            ->assertJsonPath('assessment.result', 'needs_further_review')
            ->assertJsonPath('assessment.answers.follow_ups.'.$field, 'Approximate timing or name');
        $this->travel(24)->hours();
        $payload['answers'][$answer] = 'NO';
        $this->postJson('/api/eligibility-assessments', $payload)->assertCreated()
            ->assertJsonPath('assessment.result', 'basic_requirements_met')
            ->assertJsonMissingPath('assessment.answers.follow_ups');
    }

    public function test_old_clients_and_spoofed_versions_results_and_fields_cannot_create_v5_records(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        foreach ([
            ['answers' => EligibilityEvaluatorTest::answers()],
            [...EligibilityEvaluatorTest::payload(), 'assessment_version' => 'v4'],
            [...EligibilityEvaluatorTest::payload(), 'assessment_version' => ['v5']],
            [...EligibilityEvaluatorTest::payload(), 'result' => 'basic_requirements_met'],
            [...EligibilityEvaluatorTest::payload(), 'user_id' => 999],
            [...EligibilityEvaluatorTest::payload(), 'follow_ups' => ['unknown' => 'private']],
            [...EligibilityEvaluatorTest::payload(), 'follow_ups' => 'private'],
            ['answers' => ['weightAtLeast50Kg' => 'YES', 'eatenProperMeal' => 'YES']],
        ] as $payload) {
            $this->postJson('/api/eligibility-assessments', $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('eligibility_assessments', 0);
    }

    public function test_health_followups_are_owner_only_and_absent_from_derived_context_and_notifications(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $secret = 'private-health-followup-fixture';
        $payload = EligibilityEvaluatorTest::payload(['medicationsOrSupplements' => 'YES']);
        $payload['follow_ups']['medications'] = $secret;
        $this->withToken($owner->createToken('owner')->plainTextToken)
            ->postJson('/api/eligibility-assessments', $payload)->assertCreated();
        $this->getJson('/api/eligibility-assessments/latest?assessment_version=v5')->assertOk()
            ->assertJsonPath('assessment.answers.follow_ups.medications', $secret);
        $context = json_encode(app(FlowieDonorContextService::class)->forDonor($owner));
        $this->assertStringNotContainsString($secret, $context);
        $this->assertStringNotContainsString('follow_ups', $context);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('other')->plainTextToken);
        $this->getJson('/api/eligibility-assessments/latest?assessment_version=v5&user_id='.$owner->id)->assertOk()->assertJsonPath('assessment', null);
        $this->getJson('/api/eligibility-assessments?assessment_version=v5&user_id='.$owner->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public static function outcomes(): array
    {
        return [['basic_requirements_met'], ['needs_further_review']];
    }

    #[DataProvider('outcomes')]
    public function test_both_preliminary_results_only_create_pending_activity_and_keep_all_rest_guards(string $result): void
    {
        $user = User::factory()->create();
        $user->eligibilityAssessments()->create(['assessment_version' => 'v5', 'result' => $result,
            'answers' => EligibilityEvaluatorTest::answers(), 'reasons' => [], 'assessed_at' => now()]);
        $this->withToken($user->createToken('test')->plainTextToken);
        $joined = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()
            ->assertJsonPath('participation.status', 'pending');
        $this->getJson('/api/eligibility-assessments/latest?assessment_version=v5')->assertOk()
            ->assertJsonPath('last_completed_donation_at', null)->assertJsonPath('is_on_donation_cooldown', false);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()
            ->assertJsonPath('reason', 'active_participation_exists');
        $item = DonationParticipation::findOrFail($joined->json('participation.id'));
        $item->forceFill(['status' => 'completed', 'verified_at' => now()])->save();
        $before = app(DonationCooldown::class)->metadata($user);
        $this->travel(24)->hours();
        $this->postJson('/api/eligibility-assessments', EligibilityEvaluatorTest::payload(['feverPastSevenDays' => 'YES']))
            ->assertCreated()->assertJsonPath('assessment.result', 'needs_further_review');
        $this->getJson('/api/eligibility-assessments/latest?assessment_version=v5')->assertOk()
            ->assertJsonPath('is_on_donation_cooldown', true)
            ->assertJsonPath('next_eligible_donation_at', $before['next_eligible_donation_at'])
            ->assertJsonPath('last_completed_donation_at', $before['last_completed_donation_at']);
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()
            ->assertJsonPath('reason', 'donation_cooldown_active');
        $this->assertDatabaseCount('point_transactions', 1);
    }

    public function test_legacy_history_survives_upgrade_and_safe_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_06_132100_add_v5_version_to_eligibility_assessments.php');
        $migration->down();
        $user = User::factory()->create();
        foreach (['eligible', 'not_eligible', 'temporarily_ineligible', 'needs_further_screening'] as $result) {
            $user->eligibilityAssessments()->create(['result' => $result, 'answers' => ['old' => 'YES'], 'reasons' => ['Old reason'], 'assessed_at' => now()->subDays(2)]);
        }
        $before = DB::table('eligibility_assessments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $migration->up();
        $after = DB::table('eligibility_assessments')->orderBy('id')->get()->map(function ($r) {
            $row = (array) $r;
            $this->assertNull($row['assessment_version']);
            unset($row['assessment_version']);

            return $row;
        })->all();
        $this->assertSame($before, $after);
        $migration->down();
        $migration->up();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/eligibility-assessments', EligibilityEvaluatorTest::payload())->assertCreated();
        try {
            $migration->down();
            $this->fail('Rollback must not erase or relabel V5 history.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Preserve V5 history', $e->getMessage());
        }
        $this->assertDatabaseCount('eligibility_assessments', 5);
    }

    public function test_version_mismatches_do_not_grant_participation_or_false_legacy_admin_classification(): void
    {
        foreach ([[null, 'basic_requirements_met'], [null, 'needs_further_review'],
            ['v4', 'needs_further_review'], ['v5', 'eligible'], ['v5', 'not_eligible'], [null, 'not_eligible']] as [$version, $result]) {
            $user = User::factory()->create();
            $user->eligibilityAssessments()->create(['assessment_version' => $version, 'result' => $result,
                'answers' => EligibilityEvaluatorTest::answers(), 'reasons' => [], 'assessed_at' => now()]);
            $this->app['auth']->forgetGuards();
            $this->withToken($user->createToken('test')->plainTextToken)
                ->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()
                ->assertJsonPath('reason', 'evaluation_not_eligible');
            if ($version !== null || in_array($result, ['basic_requirements_met', 'needs_further_review'], true)) {
                $this->getJson('/api/eligibility-assessments/latest')->assertStatus(426)->assertJsonMissingPath('assessment');
            }
        }
        $this->assertDatabaseCount('donation_participations', 0);
        $user = User::factory()->create();
        $user->eligibilityAssessments()->create(['assessment_version' => 'v5', 'result' => 'needs_further_review',
            'answers' => EligibilityEvaluatorTest::answers(), 'reasons' => [], 'assessed_at' => now()]);
        $listing = app(DonorService::class)->listing();
        $row = collect($listing['donors'])->firstWhere('id', $user->id);
        $this->assertSame('needs_further_review', $row['eligibility']);
        $this->assertSame(['result' => 'needs_further_review', 'version' => 'v5'], $row['assessment']);
        $this->assertSame(1, $listing['assessment_counts']['needs_further_review']);
        $this->assertSame(5, $listing['assessment_counts']['indeterminate']);
        $this->assertSame(0, $listing['summary']['eligible']);
        $this->assertSame(1, $listing['summary']['temporarilyIneligible']);
        $this->assertArrayNotHasKey('answers', $row);
    }

    public function test_old_readers_cannot_misclassify_new_results_but_legacy_only_history_stays_readable(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $user->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => ['old' => 'YES'],
            'reasons' => [], 'assessed_at' => now()->subDays(2)]);
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()->assertJsonPath('assessment.result', 'eligible');
        $this->getJson('/api/eligibility-assessments')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/eligibility-assessments', EligibilityEvaluatorTest::payload())->assertCreated();
        foreach (['/api/eligibility-assessments/latest', '/api/eligibility-assessments'] as $path) {
            $this->getJson($path)->assertStatus(426)->assertJsonPath('reason', 'assessment_version_required')
                ->assertJsonMissingPath('assessment')->assertJsonMissingPath('data');
        }
        $this->getJson('/api/eligibility-assessments/latest?assessment_version=v5')->assertOk()
            ->assertJsonPath('assessment.result', 'basic_requirements_met')->assertJsonPath('assessment.assessment_version', 'v5');
        $this->getJson('/api/eligibility-assessments?assessment_version=v5')->assertOk()->assertJsonCount(2, 'data');
    }
}
