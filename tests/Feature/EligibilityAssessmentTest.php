<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class EligibilityAssessmentTest extends TestCase
{
    // ========================================
    // ISOLATED TEST STORAGE
    // Ordinary migrations run only in SQLite memory, preserving all MySQL data.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    // ========================================
    // PERSIST EVERY RESULT STATE
    // Exercises all rules through HTTP and verifies stored answers, reasons, date,
    // and ownership match the server response without exposing account secrets.
    // ========================================
    public static function resultCases(): array
    {
        return EligibilityEvaluatorTest::cases();
    }

    #[DataProvider('resultCases')]
    public function test_submission_saves_server_result(array $overrides, string $result, int $count): void
    {
        $user = User::factory()->create();
        $answers = array_replace(EligibilityEvaluatorTest::answers(), $overrides);
        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/eligibility-assessments', ['answers' => $answers])->assertCreated()
            ->assertJsonPath('assessment.result', $result)->assertJsonCount($count, 'assessment.reasons')
            ->assertJsonMissingPath('token')->assertJsonMissingPath('assessment.user');
        $saved = $user->eligibilityAssessments()->firstOrFail();
        $this->assertSame($answers, $saved->answers);
        $this->assertSame($response->json('assessment.reasons'), $saved->reasons);
        $this->assertSame($result, $saved->result);
        $this->assertNotNull($saved->assessed_at);
        $this->assertSame($user->id, $saved->user->id);
        $this->assertDatabaseCount('eligibility_assessments', 1);
    }

    // ========================================
    // AUTHENTICATION BOUNDARY
    // All endpoints reject guests before accessing assessment data.
    // ========================================
    public function test_guests_cannot_access_assessments(): void
    {
        $this->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])->assertUnauthorized();
        $this->getJson('/api/eligibility-assessments/latest')->assertUnauthorized();
        $this->getJson('/api/eligibility-assessments')->assertUnauthorized();
    }

    // ========================================
    // INPUT ATTACKS AND MALFORMED ANSWERS
    // Unknown fields, spoofed results/owners, missing answers, and wrong types fail.
    // ========================================
    public static function invalidPayloads(): array
    {
        $valid = EligibilityEvaluatorTest::answers();
        $cases = [
            'empty' => [[]],
            'spoofed owner' => [['answers' => $valid, 'user_id' => 999]],
            'spoofed result' => [['answers' => $valid, 'result' => 'eligible']],
            'unknown root' => [['answers' => $valid, 'extra' => true]],
            'unknown answer' => [['answers' => [...$valid, 'extra' => 'NO']]],
        ];
        foreach (['medication', 'recentTattooOrPiercing', 'recentSurgeryOrHospitalization', 'recentInfectionOrAntibiotics'] as $removed) {
            $cases['removed '.$removed] = [['answers' => [...$valid, $removed => 'NO']]];
        }
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $cases['missing '.$key] = [['answers' => $missing]];
        }
        foreach (['takingAntibioticsForActiveInfection' => true, 'stillRecoveringFromProcedure' => 'maybe', 'activeOrRecoveringInfection' => 1, 'weakDizzyOrUnusuallyTired' => null, 'weight' => '50', 'sleepHours' => 25, 'medication' => true, 'currentSymptoms' => 'MAYBE', 'feelsWell' => [], 'donatedWithinThreeMonths' => 0, 'currentlyPregnant' => 'MAYBE', 'recentSurgeryOrHospitalization' => true, 'recentTattooOrPiercing' => 'sometimes', 'recentInfectionOrAntibiotics' => 1] as $key => $value) {
            $cases['invalid '.$key] = [['answers' => array_replace($valid, [$key => $value])]];
        }

        return $cases;
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $payload): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/eligibility-assessments', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('eligibility_assessments', 0);
    }

    // ========================================
    // LATEST, PAGINATION, AND OWNER ISOLATION
    // Identical timestamps sort by ID, and query-string identities cannot leak data.
    // ========================================
    public function test_latest_and_history_are_private_and_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/eligibility-assessments/latest')->assertOk()->assertJsonPath('assessment', null);
        $data = ['answers' => EligibilityEvaluatorTest::answers(), 'result' => 'eligible', 'reasons' => [], 'assessed_at' => now()];
        $other->eligibilityAssessments()->create($data);
        for ($i = 0; $i < 21; $i++) {
            $latest = $user->eligibilityAssessments()->create($data);
        }
        $this->getJson('/api/eligibility-assessments/latest?user_id='.$other->id)
            ->assertOk()->assertJsonPath('assessment.id', $latest->id);
        $history = $this->getJson('/api/eligibility-assessments?user_id='.$other->id)
            ->assertOk()->assertJsonPath('total', 21)->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $latest->id);
        $this->assertSame([$user->id], array_values(array_unique(array_column($history->json('data'), 'user_id'))));
        $this->getJson('/api/eligibility-assessments?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    // Legacy six-answer snapshots remain readable and still start a cooldown.
    public static function legacyResults(): array
    {
        return [['eligible'], ['temporarily_ineligible'], ['needs_further_screening']];
    }

    #[DataProvider('legacyResults')]
    public function test_legacy_six_answer_history_is_preserved(string $result): void
    {
        $user = User::factory()->create();
        $answers = ['weight' => 50, 'sleepHours' => 5, 'currentSymptoms' => 'NO', 'medication' => 'NO', 'donatedWithinThreeMonths' => 'NO', 'feelsWell' => 'YES'];
        $row = $user->eligibilityAssessments()->create(['answers' => $answers, 'result' => $result, 'reasons' => [], 'assessed_at' => now()]);
        $original = $row->fresh()->getRawOriginal();
        $this->withToken($user->createToken('legacy')->plainTextToken);
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()->assertJsonPath('assessment.answers', $answers)->assertJsonPath('assessment.result', $result)->assertJsonPath('cooldown_active', true);
        $this->getJson('/api/eligibility-assessments')->assertOk()->assertJsonCount(6, 'data.0.answers');
        $this->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])->assertStatus(409);
        $this->assertSame($original, $row->fresh()->getRawOriginal());
        $this->assertDatabaseCount('eligibility_assessments', 1);
    }

    // Widen the original enum while preserving complete legacy snapshots.
    public function test_result_schema_upgrade_preserves_existing_history(): void
    {
        Schema::table('eligibility_assessments', function (Blueprint $table) {
            $table->enum('result', ['eligible', 'temporarily_ineligible', 'needs_further_screening'])->change();
        });
        $user = User::factory()->create();
        foreach (['eligible', 'temporarily_ineligible', 'needs_further_screening'] as $result) {
            $user->eligibilityAssessments()->create(['answers' => ['medication' => 'YES'], 'result' => $result,
                'reasons' => ['Legacy reason'], 'assessed_at' => now()->subDays(2)]);
        }
        $before = DB::table('eligibility_assessments')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_09_12_145322_allow_binary_eligibility_results.php');
        $migration->up();
        $this->assertSame($before, DB::table('eligibility_assessments')->orderBy('id')->get()->toJson());
        $this->withToken($user->createToken('upgrade')->plainTextToken)
            ->postJson('/api/eligibility-assessments', ['answers' => [...EligibilityEvaluatorTest::answers(), 'weight' => 49]])
            ->assertCreated()->assertJsonPath('assessment.result', 'not_eligible');
        $this->assertDatabaseCount('eligibility_assessments', 4);
    }
}
