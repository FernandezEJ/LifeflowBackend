<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class EligibilityCooldownTest extends TestCase
{
    // All assessment writes in this suite use isolated SQLite memory.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(now()->startOfSecond());
    }

    public static function blockedTimes(): array
    {
        return ['five minutes' => [300], '23h59m' => [86340], 'last second' => [86399]];
    }

    // Rejection returns history and cannot overwrite answers, reasons or timestamps.
    #[DataProvider('blockedTimes')]
    public function test_cooldown_blocks_without_changing_history(int $seconds): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()
            ->assertJsonPath('assessment', null)->assertJsonPath('cooldown_active', false)
            ->assertJsonPath('remaining_seconds', 0)->assertJsonPath('next_allowed_at', null);
        $first = $this->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])
            ->assertCreated()->assertJsonPath('cooldown_active', true)->assertJsonPath('remaining_seconds', 86400);
        $this->assertDatabaseCount('eligibility_assessments', 1);
        $original = $user->eligibilityAssessments()->firstOrFail()->getRawOriginal();
        $next = $first->json('next_allowed_at');
        $this->travel($seconds)->seconds();
        $blocked = $this->postJson('/api/eligibility-assessments', [
            'answers' => [...EligibilityEvaluatorTest::answers(), 'currentSymptoms' => 'YES'],
        ])->assertStatus(409)->assertJsonPath('assessment.id', $first->json('assessment.id'))
            ->assertJsonPath('latest_assessment.id', $first->json('assessment.id'))
            ->assertJsonPath('cooldown_active', true)->assertJsonPath('next_allowed_at', $next)
            ->assertJsonPath('remaining_seconds', 86400 - $seconds)
            ->assertJsonMissingPath('created');
        $this->assertEquals($first->json('assessment'), $blocked->json('latest_assessment'));
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()
            ->assertJsonPath('assessment', $blocked->json('assessment'))
            ->assertJsonPath('remaining_seconds', 86400 - $seconds);
        $this->assertSame($original, $user->eligibilityAssessments()->firstOrFail()->getRawOriginal());
        $this->assertDatabaseCount('eligibility_assessments', 1);
    }

    public static function allowedTimes(): array
    {
        return ['exactly 24 hours' => [86400], 'after 24 hours' => [90000]];
    }

    // A later valid day adds a new owned row and leaves older history intact.
    #[DataProvider('allowedTimes')]
    public function test_new_day_preserves_history(int $seconds): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $first = $this->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])->assertCreated();
        $original = $user->eligibilityAssessments()->firstOrFail()->getRawOriginal();
        $this->travel($seconds)->seconds();
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()
            ->assertJsonPath('cooldown_active', false)->assertJsonPath('remaining_seconds', 0);
        $second = $this->postJson('/api/eligibility-assessments', [
            'answers' => [...EligibilityEvaluatorTest::answers(), 'currentSymptoms' => 'YES'],
        ])->assertCreated()->assertJsonPath('assessment.user_id', $user->id)
            ->assertJsonPath('assessment.result', 'not_eligible');
        $this->assertNotSame($first->json('assessment.id'), $second->json('assessment.id'));
        $this->assertSame($original, $user->eligibilityAssessments()->findOrFail($first->json('assessment.id'))->getRawOriginal());
        $this->getJson('/api/eligibility-assessments')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->json('assessment.id'))
            ->assertJsonPath('data.1.id', $first->json('assessment.id'));
        $this->assertDatabaseCount('eligibility_assessments', 2);
    }

    public function test_another_donor_has_an_independent_private_cooldown(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->withToken($first->createToken('one')->plainTextToken)
            ->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])->assertCreated();
        // Clear the guard's cached user to model a separate authenticated request.
        app('auth')->forgetGuards();
        $this->withToken($second->createToken('two')->plainTextToken);
        $this->getJson('/api/eligibility-assessments/latest?user_id='.$first->id)->assertOk()
            ->assertJsonPath('assessment', null)->assertJsonPath('cooldown_active', false);
        $this->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()])
            ->assertCreated()->assertJsonPath('assessment.user_id', $second->id);
        $this->getJson('/api/eligibility-assessments?user_id='.$first->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $second->id);
        $this->assertDatabaseCount('eligibility_assessments', 2);
    }
}
