<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RedCrossDonationTest extends TestCase
{
    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        Storage::fake('proofs');
        $this->donor = User::factory()->create();
        $this->donor->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $this->withToken($this->donor->createToken('test')->plainTextToken);
    }

    private function join(): TestResponse
    {
        return $this->postJson('/api/donation-opportunities/red-cross-dagupan/join');
    }

    private function proof(int $id): array
    {
        return ['proof' => UploadedFile::fake()->create('proof.jpg', 1, 'image/jpeg')];
    }

    private function admin(): DonationOpportunity
    {
        return DonationOpportunity::create(['title' => 'Admin bloodletting', 'description' => 'Test-only opportunity', 'location' => 'Test venue',
            'event_date' => now()->toDateString(), 'status' => 'published', 'published_at' => now()->subHour(), 'expires_at' => now()->addDay()]);
    }

    public function test_permanent_detail_is_available_without_posts_but_still_requires_authentication(): void
    {
        $this->getJson('/api/donation-opportunities')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertOk()
            ->assertJsonPath('opportunity.source_type', 'red_cross_dagupan')
            ->assertJsonPath('opportunity.title', 'Philippine Red Cross ? Dagupan City Chapter')
            ->assertJsonPath('opportunity.event_date', null);
        $this->getJson('/api/donation-opportunities/unknown-red-cross')->assertNotFound();
        $admin = $this->admin();
        $this->getJson('/api/donation-opportunities')->assertJsonPath('data.0.id', $admin->id);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertUnauthorized();
        $this->join()->assertUnauthorized();
    }

    public function test_red_cross_uses_shared_proof_activity_completion_points_and_achievement(): void
    {
        $id = $this->join()->assertCreated()->assertJsonPath('participation.source_type', 'red_cross_dagupan')
            ->assertJsonPath('participation.donation_opportunity_id', null)->json('participation.id');
        $this->join()->assertConflict();
        $this->getJson('/api/donation-participations')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.opportunity.source_type', 'red_cross_dagupan');
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0);
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proof($id))->assertOk()->assertJsonPath('participation.status', 'for_verification');
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertConflict();
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 0);
        $row = DonationParticipation::findOrFail($id);
        $row->forceFill(['status' => 'completed', 'verified_at' => now()])->save();
        $row->save();
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 1)->assertJsonPath('achievement.key', 'first_time_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 300);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->donor->id, 'message' => 'Your donation has been verified. 300 points were added.']);
        Queue::assertPushed(SendImportantPush::class, 1);
        $this->getJson('/api/donation-participations/'.$id)->assertJsonPath('participation.status', 'completed');
        $this->assertDatabaseCount('donation_records', 0);
        // The permanent source supports later donations, rather than one join for life.
        $this->travelTo(now()->addMonthsNoOverflow(3));
        $this->donor->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $this->join()->assertCreated();
    }

    public function test_rejected_cancelled_and_pending_rows_do_not_count_and_can_rejoin(): void
    {
        $id = $this->join()->json('participation.id');
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertOk();
        $next = $this->join()->assertCreated()->json('participation.id');
        $this->postJson('/api/donation-participations/'.$next.'/proof', $this->proof($next))->assertOk();
        DonationParticipation::findOrFail($next)->forceFill(['status' => 'rejected', 'rejection_reason' => 'Private review detail'])->save();
        $this->join()->assertCreated();
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0)->assertJsonPath('achievement.key', 'new_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 0);
        $this->getJson('/api/donation-participations')->assertJsonPath('total', 3);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_mixed_admin_and_red_cross_donations_share_the_complete_point_schedule(): void
    {
        $sum = 0;
        foreach ([300, 350, 400, 450, 500, 500] as $index => $points) {
            if ($index > 0) {
                $this->travelTo(now()->addMonthsNoOverflow(3));
                $this->donor->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
            }
            if ($index === 0) {
                $admin = $this->admin();
                $id = $this->postJson('/api/donation-opportunities/'.$admin->id.'/join')->assertCreated()
                    ->assertJsonPath('participation.source_type', 'admin_announcement')->json('participation.id');
                $this->assertDatabaseHas('donation_participations', ['id' => $id, 'donation_opportunity_id' => $admin->id]);
            } else {
                $id = $this->join()->assertCreated()->json('participation.id');
            }
            $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proof($id))->assertOk();
            DonationParticipation::findOrFail($id)->forceFill(['status' => 'completed', 'verified_at' => now()])->save();
            $sum += $points;
            $this->getJson('/api/points/summary')->assertJsonPath('current_balance', $sum);
            $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', $index + 1);
        }
        $this->getJson('/api/donation-summary')->assertJsonPath('achievement.key', 'silver_donor');
        $this->assertDatabaseCount('notifications', 6);
        $this->assertDatabaseCount('point_transactions', 6);
    }

    public function test_linked_legacy_record_is_not_double_counted_and_unlinked_history_is_preserved(): void
    {
        $id = $this->join()->json('participation.id');
        DonationParticipation::findOrFail($id)->forceFill(['status' => 'completed', 'verified_at' => now()])->save();
        foreach ([$id, null] as $link) {
            (new DonationRecord)->forceFill(['user_id' => $this->donor->id, 'donation_participation_id' => $link,
                'donation_date' => now()->toDateString(), 'location' => 'Test facility', 'status' => 'completed', 'submitted_at' => now()])->save();
        }
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 2);
        $this->getJson('/api/donation-participations')->assertJsonPath('total', 1);
        $this->assertDatabaseCount('donation_records', 2);
    }

    public function test_donor_cannot_forge_source_status_or_access_another_donors_proof(): void
    {
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join', ['source_type' => 'admin_announcement', 'status' => 'completed'])->assertUnprocessable();
        $id = $this->join()->json('participation.id');
        $this->app['auth']->forgetGuards();
        $other = User::factory()->create();
        $this->withToken($other->createToken('other')->plainTextToken);
        $this->getJson('/api/donation-participations/'.$id)->assertNotFound();
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proof($id))->assertNotFound();
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertNotFound();
        $this->assertDatabaseHas('donation_participations', ['id' => $id, 'user_id' => $this->donor->id, 'status' => 'pending']);
    }
}
