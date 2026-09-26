<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\DonorAchievementServiceTest;

class DonationRecordTest extends TestCase
{
    // ========================================
    // ISOLATED DATABASE
    // Ordinary migrations run in SQLite memory, never against donor MySQL data.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
    }

    // ========================================
    // RETIRED MANUAL SUBMISSION
    // The original donor creation path is disabled; only opportunity joins are supported.
    // ========================================
    public function test_manual_submission_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->postJson('/api/donation-records', [
            'donation_date' => now()->toDateString(), 'location' => 'Community Center', 'notes' => 'Donation record',
        ])->assertStatus(405);
        $this->assertDatabaseCount('donation_records', 0);
        $this->getJson('/api/donation-summary')->assertOk()->assertJsonPath('total_donations', 0);
    }

    // ========================================
    // INPUT VALIDATION AND SELF-VERIFICATION ATTACKS
    // Rejects privileged fields, invalid dates, and malformed or oversized text.
    // ========================================
    public static function invalid(): array
    {
        $cases = [];
        foreach (['user_id' => 99, 'status' => 'completed', 'verified_at' => '2026-01-01', 'total_donations' => 10,
            'achievement' => 'gold_donor', 'streak_count' => 3, 'submitted_at' => '2026-01-01',
            'donation_date' => '2999-01-01', 'location' => '', 'notes' => []] as $key => $value) {
            $cases[$key] = [[$key => $value]];
        }
        $cases['invalid calendar'] = [['donation_date' => '2026-02-30']];
        $cases['long location'] = [['location' => str_repeat('x', 256)]];
        $cases['long notes'] = [['notes' => str_repeat('x', 5001)]];

        return $cases;
    }

    #[DataProvider('invalid')]
    public function test_invalid_submissions(array $changes): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)->postJson('/api/donation-records',
            array_replace(['donation_date' => '2020-01-01', 'location' => 'Center'], $changes))->assertStatus(405);
        $this->assertDatabaseCount('donation_records', 0);
    }

    // ========================================
    // PRIVATE HISTORY AND DETAILS
    // Guests and other owners cannot access records, and no approval route exists.
    // ========================================
    public function test_history_details_and_authentication_boundaries(): void
    {
        $this->postJson('/api/donation-records', [])->assertStatus(405);
        foreach (['/api/donation-records', '/api/donation-records/1', '/api/donation-summary'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->record($other, 'completed');
        for ($i = 0; $i < 21; $i++) {
            $newest = $this->record($user, 'pending');
        }
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/donation-records?user_id='.$other->id)
            ->assertOk()->assertJsonPath('total', 21)->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $newest->id);
        $this->getJson('/api/donation-records?page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/donation-records?status=completed')->assertJsonPath('total', 0);
        $this->getJson('/api/donation-records/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/donation-records/'.$newest->id)->assertOk()->assertJsonPath('donation_record.id', $newest->id);
        $this->putJson('/api/donation-records/'.$newest->id, ['status' => 'completed'])->assertStatus(405);
        $this->postJson('/api/donation-records', [])->assertStatus(405);
    }

    // ========================================
    // COMPLETED-ONLY SUMMARY
    // Test fixtures simulate future staff decisions without exposing approval APIs.
    // ========================================
    public static function milestones(): array
    {
        return DonorAchievementServiceTest::milestones();
    }

    #[DataProvider('milestones')]
    public function test_summary_counts_completed_only(int $count, string $key, string $label): void
    {
        $user = User::factory()->create();
        $this->record($user, 'pending');
        $this->record($user, 'rejected');
        $this->record(User::factory()->create(), 'completed');
        for ($i = 0; $i < $count; $i++) {
            $this->record($user, 'completed');
        }
        $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/donation-summary')
            ->assertOk()->assertExactJson(['total_donations' => $count, 'achievement' => compact('key', 'label')]);
    }

    // ========================================
    // TRUSTED TEST FIXTURE
    // Only tests assign verification states directly; donors cannot do this over HTTP.
    // ========================================
    private function record(User $user, string $status)
    {
        $record = $user->donationRecords()->make(['donation_date' => '2020-01-01', 'location' => 'Center']);
        $record->status = $status;
        $record->submitted_at = now();
        $record->verified_at = $status === 'pending' ? null : now();
        $record->save();

        return $record;
    }
}
