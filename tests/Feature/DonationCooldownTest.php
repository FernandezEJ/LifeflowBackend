<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\Notification;
use App\Models\User;
use App\Services\DonationCooldown;
use App\Services\PushNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class DonationCooldownTest extends TestCase
{
    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Manila'));
        $this->donor = User::factory()->create();
        $this->withToken($this->donor->createToken('test')->plainTextToken);
    }

    private function participation(string $status, ?string $verifiedAt = null, ?User $donor = null): DonationParticipation
    {
        $item = new DonationParticipation;
        $item->forceFill(['user_id' => ($donor ?? $this->donor)->id, 'source_type' => 'red_cross_dagupan',
            'status' => $status, 'joined_at' => now(),
            'verified_at' => $verifiedAt ? CarbonImmutable::parse($verifiedAt)->utc() : null]);
        $item->save();

        return $item;
    }

    private function assessment(string $result = 'eligible', ?string $at = null): void
    {
        $this->donor->eligibilityAssessments()->create(['result' => $result,
            'answers' => EligibilityEvaluatorTest::answers(), 'reasons' => [],
            'assessed_at' => $at ? CarbonImmutable::parse($at)->utc() : now()]);
    }

    private function metadata(): array
    {
        return app(DonationCooldown::class)->metadata($this->donor);
    }

    public function test_no_completed_history_and_other_donors_history_do_not_start_cooldown(): void
    {
        $this->participation('completed', null, User::factory()->create());
        $this->getJson('/api/eligibility-assessments/latest')->assertOk()
            ->assertJsonPath('is_on_donation_cooldown', false)
            ->assertJsonPath('last_completed_donation_at', null)
            ->assertJsonPath('next_eligible_donation_at', null);
        $this->assessment();
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated();
    }

    public static function nonCompleted(): array
    {
        return array_map(fn (string $status) => [$status], ['pending', 'for_verification', 'needs_revision', 'rejected', 'cancelled']);
    }

    #[DataProvider('nonCompleted')]
    public function test_only_completed_status_starts_rest_and_counts_for_points_and_achievements(string $status): void
    {
        $this->participation($status);
        $this->assertFalse($this->metadata()['is_on_donation_cooldown']);
        $this->assertNull($this->metadata()['last_completed_donation_at']);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0)->assertJsonPath('achievement.key', 'new_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 0);
        $this->assessment();
        $response = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join');
        if (in_array($status, ['pending', 'for_verification', 'needs_revision'], true)) {
            $response->assertConflict()->assertJsonPath('reason', 'active_participation_exists');
        } else {
            $response->assertCreated();
        }
    }

    public function test_completed_transition_automatically_records_timestamp_and_preserves_award_once(): void
    {
        $item = $this->participation('for_verification');
        $item->forceFill(['status' => 'completed'])->save();
        $timestamp = $item->fresh()->verified_at;
        $this->assertNotNull($timestamp);
        $this->travel(1)->minute();
        $item->save();
        $this->assertEquals($timestamp, $item->fresh()->verified_at);
        $this->assertTrue($this->metadata()['is_on_donation_cooldown']);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 1)->assertJsonPath('achievement.key', 'first_time_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 300);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['type' => 'donation_completed']);
        Queue::assertPushed(SendImportantPush::class, 1);
    }

    public static function calendarCases(): array
    {
        return [
            'ordinary calendar months' => ['2026-01-15T10:00:00+08:00', '2026-04-15T10:00:00+08:00'],
            'year crossing' => ['2026-10-01T10:00:00+08:00', '2027-01-01T10:00:00+08:00'],
            'month end without overflow' => ['2026-01-31T10:00:00+08:00', '2026-04-30T10:00:00+08:00'],
            'UTC input crosses Manila date' => ['2026-01-31T16:00:00Z', '2026-05-01T00:00:00+08:00'],
        ];
    }

    #[DataProvider('calendarCases')]
    public function test_three_calendar_months_and_exact_boundary_in_manila(string $completed, string $next): void
    {
        $this->travelTo(CarbonImmutable::parse($completed));
        $this->participation('completed', $completed);
        $this->assertSame($next, $this->metadata()['next_eligible_donation_at']);
        $this->assertTrue($this->metadata()['is_on_donation_cooldown']);
        $this->travelTo(CarbonImmutable::parse($next)->subSecond());
        $this->assertTrue($this->metadata()['is_on_donation_cooldown']);
        $this->assertSame(1, $this->metadata()['donation_cooldown_remaining_seconds']);
        $this->travelTo(CarbonImmutable::parse($next));
        $this->assertFalse($this->metadata()['is_on_donation_cooldown']);
        $this->assertSame(0, $this->metadata()['donation_cooldown_remaining_seconds']);
        $this->assessment();
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated();
    }

    public function test_latest_completed_timestamp_wins_over_id_and_newer_noncompleted_history(): void
    {
        $latest = $this->participation('completed', '2026-09-15T12:00:00+08:00');
        $this->participation('completed', '2026-05-01T12:00:00+08:00');
        $this->participation('rejected', '2026-10-01T12:00:00+08:00');
        $this->assertSame($latest->id, app(DonationCooldown::class)->latest($this->donor)->id);
        $this->assertSame('2026-12-15T12:00:00+08:00', $this->metadata()['next_eligible_donation_at']);
    }

    public static function sources(): array
    {
        return [['red-cross-dagupan'], ['admin']];
    }

    #[DataProvider('sources')]
    public function test_backend_blocks_both_sources_despite_eligible_three_month_assessment_answer(string $source): void
    {
        $this->participation('completed');
        $this->assessment();
        if ($source === 'admin') {
            $source = (string) DonationOpportunity::create(['title' => 'Test event', 'description' => 'Donation event',
                'location' => 'Test facility', 'event_date' => now()->toDateString(), 'status' => 'published',
                'published_at' => now()->subHour(), 'expires_at' => now()->addDay()])->id;
        }
        $this->postJson('/api/donation-opportunities/'.$source.'/join')->assertConflict()
            ->assertJsonPath('reason', 'donation_cooldown_active')
            ->assertJsonPath('is_on_donation_cooldown', true)
            ->assertJsonPath('last_completed_donation_at', '2026-10-01T10:00:00+08:00')
            ->assertJsonPath('next_eligible_donation_at', '2027-01-01T10:00:00+08:00');
        $this->assertDatabaseCount('donation_participations', 1);
        $this->getJson('/api/eligibility-assessments/latest')->assertJsonPath('assessment.result', 'eligible')
            ->assertJsonPath('assessment.answers.threeMonthsSinceLastDonation', 'YES')
            ->assertJsonPath('is_on_donation_cooldown', true);
    }

    public function test_active_participation_precedes_rest_and_rest_precedes_missing_assessment(): void
    {
        $this->participation('completed');
        $active = $this->participation('needs_revision');
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()
            ->assertJsonPath('reason', 'active_participation_exists')->assertJsonPath('participation_id', $active->id);
        $active->forceFill(['status' => 'rejected'])->save();
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()
            ->assertJsonPath('reason', 'donation_cooldown_active');
    }

    public static function assessmentGates(): array
    {
        return [['missing', 'evaluation_required'], ['expired', 'evaluation_required'], ['not_eligible', 'evaluation_not_eligible']];
    }

    #[DataProvider('assessmentGates')]
    public function test_expired_rest_still_requires_fresh_eligible_assessment(string $kind, string $reason): void
    {
        $this->participation('completed', '2026-07-01T10:00:00+08:00');
        if ($kind !== 'missing') {
            $this->assessment($kind === 'not_eligible' ? 'not_eligible' : 'eligible', $kind === 'expired' ? '2026-09-30T10:00:00+08:00' : null);
        }
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()->assertJsonPath('reason', $reason);
    }

    public function test_trusted_legacy_history_is_preserved_and_linked_record_does_not_override_completion(): void
    {
        $item = $this->participation('completed', '2026-07-01T10:00:00+08:00');
        $linked = new DonationRecord;
        $linked->forceFill(['user_id' => $this->donor->id, 'donation_participation_id' => $item->id,
            'donation_date' => '2026-10-01', 'status' => 'completed', 'location' => 'Test', 'submitted_at' => now()])->save();
        $this->assertFalse($this->metadata()['is_on_donation_cooldown']);
        $unlinked = new DonationRecord;
        $unlinked->forceFill(['user_id' => $this->donor->id, 'donation_date' => '2026-09-01',
            'status' => 'completed', 'location' => 'Historical facility', 'submitted_at' => now()])->save();
        $original = $unlinked->fresh()->getRawOriginal();
        $this->assertSame('2026-12-01T00:00:00+08:00', $this->metadata()['next_eligible_donation_at']);
        $this->assertSame($original, $unlinked->fresh()->getRawOriginal());
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 2);
    }

    public function test_old_completed_participation_without_verified_at_uses_saved_timestamp_without_rewriting(): void
    {
        $id = DB::table('donation_participations')->insertGetId(['user_id' => $this->donor->id,
            'source_type' => 'red_cross_dagupan', 'status' => 'completed', 'joined_at' => '2026-09-01 02:00:00',
            'created_at' => '2026-09-01 02:00:00', 'updated_at' => '2026-09-02 02:00:00']);
        $original = DonationParticipation::findOrFail($id)->getRawOriginal();
        $this->assertSame('2026-12-02T10:00:00+08:00', $this->metadata()['next_eligible_donation_at']);
        $this->assertSame($original, DonationParticipation::findOrFail($id)->getRawOriginal());
    }

    public function test_scheduler_notifies_once_at_boundary_and_once_for_each_later_completed_cycle(): void
    {
        $this->participation('completed');
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 0 donation rest period notification(s).')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2027-01-01T10:00:00+08:00'));
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 1 donation rest period notification(s).')->assertExitCode(0);
        $notice = Notification::where('type', 'donation_cooldown_complete')->sole();
        $this->assertSame('You can donate again!', $notice->title);
        $this->assertSame('2027-01-01T10:00:00+08:00', $notice->data['next_eligible_donation_at']);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.type', 'donation_cooldown_complete');
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 0 donation rest period notification(s).')->assertExitCode(0);
        $this->assertSame(1, Notification::where('type', 'donation_cooldown_complete')->count());
        Queue::assertPushed(SendImportantPush::class, 2);
        $this->participation('completed');
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 0 donation rest period notification(s).')->assertExitCode(0);
        app(PushNotificationService::class)->send($notice);
        $this->assertSame('not_due', $notice->fresh()->push_status);
        $this->travelTo(CarbonImmutable::parse('2027-04-01T10:00:00+08:00'));
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 1 donation rest period notification(s).')->assertExitCode(0);
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 0 donation rest period notification(s).')->assertExitCode(0);
        $this->assertSame(2, Notification::where('type', 'donation_cooldown_complete')->count());
        Queue::assertPushed(SendImportantPush::class, 4);
    }

    public function test_newer_completed_cycle_suppresses_an_older_unnotified_cycle(): void
    {
        $this->participation('completed', '2026-01-01T10:00:00+08:00');
        $this->participation('completed');
        $this->artisan('donations:notify-cooldown-complete')->expectsOutput('Created 0 donation rest period notification(s).')->assertExitCode(0);
        $this->assertSame(0, Notification::where('type', 'donation_cooldown_complete')->count());
    }
}
