<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\User;
use App\Services\FlowieDonorContextService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnnouncementHistoryPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->freezeTime();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('proofs');
        Storage::fake('public');
    }

    private function signIn(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($user->isAdminPanelUser() ? 'admin-web' : 'mobile')->plainTextToken);
    }

    private function publication(): DonationOpportunity
    {
        return DonationOpportunity::create(['title' => 'Published drive', 'description' => 'Published information',
            'location' => 'Public center', 'event_date' => now()->addDays(2)->toDateString(),
            'status' => 'published', 'published_at' => now()->subHour(), 'expires_at' => now()->addDays(2),
            'source_type' => 'admin_announcement']);
    }

    private function participation(User $owner, DonationOpportunity $post, string $status = 'pending'): DonationParticipation
    {
        $entry = new DonationParticipation;
        $entry->forceFill(['user_id' => $owner->id, 'donation_opportunity_id' => $post->id,
            'source_type' => 'admin_announcement', 'status' => $status, 'joined_at' => now()->subHour(),
            'proof_path' => 'owned-proof.pdf', 'proof_original_name' => 'proof.pdf', 'proof_mime_type' => 'application/pdf',
            'proof_size' => 5, 'proof_uploaded_at' => now()->subMinute(),
            'verified_at' => $status === 'completed' ? now() : null,
            'revision_reason' => $status === 'needs_revision' ? 'Please clarify the date.' : null])->saveQuietly();
        $entry->forceFill(['proof_path' => 'donation-proofs/'.$owner->id.'/'.$entry->id.'/11111111-1111-4111-8111-111111111111.pdf'])->saveQuietly();
        Storage::disk('proofs')->put($entry->proof_path, '%PDF-');

        return $entry;
    }

    public static function historyPaths(): array
    {
        return [['list'], ['detail']];
    }

    #[DataProvider('historyPaths')]
    public function test_real_publish_join_draft_edit_never_discloses_private_fields(string $path): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();
        $this->signIn($staff);
        $data = ['title' => 'Approved publication', 'description' => 'Approved public text', 'location' => 'Public center',
            'donation_date' => now()->addDays(2)->toDateString(), 'status' => 'published', 'notify_donors' => false];
        $id = $this->postJson('/api/admin/announcements', $data)->assertCreated()->json('announcement.id');
        $this->signIn($owner);
        $owner->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $entry = $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertCreated()->json('participation.id');
        $this->signIn($staff);
        $this->postJson('/api/admin/announcements/'.$id.'/draft')->assertOk();
        $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'PRIVATE_DRAFT_CANARY rich text', 'bold' => true]]]]];
        $this->putJson('/api/admin/announcements/'.$id, [...$data, 'status' => 'draft', 'title' => 'PRIVATE_DRAFT_CANARY title',
            'description' => 'PRIVATE_DRAFT_CANARY body', 'description_document' => $document, 'location' => 'PRIVATE_DRAFT_CANARY address',
            'donation_date' => now()->addDays(9)->toDateString()])->assertOk();
        DonationOpportunity::findOrFail($id)->update(['image_path' => 'announcements/PRIVATE_DRAFT_CANARY.png', 'start_time' => '03:21:00']);
        $this->signIn($owner);
        $response = $this->getJson('/api/donation-participations'.($path === 'detail' ? '/'.$entry : ''))->assertOk();
        $this->assertStringNotContainsString('PRIVATE_DRAFT_CANARY', $response->getContent());
        $prefix = $path === 'detail' ? 'participation' : 'data.0';
        $response->assertJsonPath($prefix.'.id', $entry)->assertJsonPath($prefix.'.status', 'pending')
            ->assertJsonPath($prefix.'.opportunity.status', 'unavailable')
            ->assertJsonPath($prefix.'.opportunity.description_document', null)
            ->assertJsonPath($prefix.'.opportunity.image_url', null)->assertJsonPath($prefix.'.opportunity.event_date', null)
            ->assertJsonPath($prefix.'.opportunity.start_time', null)->assertJsonMissingPath($prefix.'.opportunity.updated_at');
        $this->getJson('/api/announcements/'.$id)->assertNotFound();
        $this->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('donation_participations', 1);
        Http::assertNothingSent();
    }

    public static function retainedStates(): array
    {
        $cases = [];
        foreach (['pending', 'for_verification', 'needs_revision', 'completed', 'rejected', 'cancelled'] as $status) {
            foreach (['draft', 'deleted', 'expired', 'missing'] as $state) {
                $cases[$status.' '.$state] = [$status, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('retainedStates')]
    public function test_history_and_proof_facts_survive_unavailable_or_expired_posts(string $status, string $state): void
    {
        $owner = User::factory()->create();
        $post = $this->publication();
        $entry = $this->participation($owner, $post, $status);
        $before = $entry->fresh()->getAttributes();
        match ($state) {
            'draft' => $post->update(['status' => 'draft', 'title' => 'PRIVATE_DRAFT_CANARY']),
            'deleted' => $post->delete(),
            'expired' => $post->update(['expires_at' => now()->subSecond()]),
            // Existing nullable legacy linkage, not a force-delete of referenced donor history.
            'missing' => $entry->forceFill(['donation_opportunity_id' => null])->saveQuietly(),
        };
        $this->signIn($owner);
        $result = $this->getJson('/api/donation-participations/'.$entry->id)->assertOk()
            ->assertJsonPath('participation.id', $entry->id)->assertJsonPath('participation.status', $status)
            ->assertJsonPath('participation.proof_size', 5)->assertJsonMissingPath('participation.proof_path');
        $this->assertStringNotContainsString('PRIVATE_DRAFT_CANARY', $result->getContent());
        $this->getJson('/api/donation-participations?status='.$status)->assertJsonPath('total', 1);
        $this->getJson('/api/donation-participations/'.$entry->id.'/proof')->assertOk();
        if ($state === 'expired') {
            $result->assertJsonPath('participation.opportunity.title', 'Published drive');
        } else {
            $result->assertJsonPath('participation.opportunity.status', 'unavailable');
        }
        $after = $entry->fresh()->getAttributes();
        foreach (['status', 'joined_at', 'verified_at', 'proof_path', 'proof_size', 'proof_uploaded_at', 'revision_reason'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('donation_participations', 1);
    }

    public function test_owner_boundary_and_account_guards_remain_on_history(): void
    {
        $owner = User::factory()->create();
        $entry = $this->participation($owner, $this->publication());
        $url = '/api/donation-participations/'.$entry->id;
        $this->getJson($url)->assertUnauthorized();
        $other = User::factory()->create();
        $this->signIn($other);
        $this->getJson($url.'?user_id='.$owner->id)->assertNotFound();
        $this->getJson('/api/donation-participations')->assertJsonPath('total', 0);
        foreach ([['deactivated_at' => now()], ['email_verified_at' => null], ['role' => 'admin'], ['role' => 'super_admin']] as $attributes) {
            $this->signIn(User::factory()->create($attributes));
            $this->getJson($url)->assertForbidden();
            $this->getJson('/api/donation-participations')->assertForbidden();
        }
    }

    public function test_published_edits_and_expired_notification_stay_readable_but_drafts_do_not(): void
    {
        $owner = User::factory()->create();
        $post = $this->publication();
        $entry = $this->participation($owner, $post);
        $notice = $owner->notifications()->create(['type' => 'admin_announcement', 'title' => 'Published notice',
            'message' => 'Published notice', 'event_key' => 'r3:published', 'data' => ['opportunity_id' => $post->id]]);
        $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Published edit', 'italic' => true]]]]];
        $post->update(['title' => 'Published edit', 'description_document' => $document]);
        $this->signIn($owner);
        $this->getJson('/api/announcements/'.$post->id)->assertOk()->assertJsonPath('opportunity.title', 'Published edit');
        $this->getJson('/api/donation-participations/'.$entry->id)->assertJsonPath('participation.opportunity.description_document', $document);
        $post->update(['expires_at' => now()->subSecond()]);
        $url = '/api/notifications/'.$notice->id.'/announcement';
        $this->getJson($url)->assertOk()->assertJsonPath('view_state', 'expired')->assertJsonPath('read_only', true)
            ->assertJsonPath('opportunity.description_document', $document);
        $this->assertNull($notice->fresh()->read_at);
        $this->signIn(User::factory()->create());
        $this->getJson($url)->assertNotFound();
        $this->signIn($owner);
        $post->update(['status' => 'draft', 'title' => 'PRIVATE_DRAFT_CANARY']);
        $this->getJson($url)->assertNotFound();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_flowie_does_not_send_an_unpublished_activity_title_to_groq(): void
    {
        $owner = User::factory()->create();
        $post = $this->publication();
        $this->participation($owner, $post);
        $post->update(['status' => 'draft', 'title' => 'PRIVATE_DRAFT_CANARY']);
        $context = app(FlowieDonorContextService::class)->forDonor($owner);
        $this->assertNull($context['active_participation_opportunity_title']);
        $this->assertSame('pending', $context['active_participation_status']);
        config(['services.groq.key' => 'synthetic-key', 'services.groq.model' => 'synthetic-model']);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Check Activity for your donation progress.']]]])]);
        $this->signIn($owner);
        $this->postJson('/api/flowie/chat', ['message' => 'What is my active donation activity?'])->assertOk();
        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data());

            return ! str_contains($payload, 'PRIVATE_DRAFT_CANARY') && str_contains($payload, 'active_participation_status');
        });
    }

    public function test_draft_content_is_not_returned_in_cancel_or_proof_submission_replies(): void
    {
        $owner = User::factory()->create();
        $post = $this->publication();
        $entry = $this->participation($owner, $post);
        $post->update(['status' => 'draft', 'description' => 'PRIVATE_DRAFT_CANARY']);
        $this->signIn($owner);
        $response = $this->postJson('/api/donation-participations/'.$entry->id.'/proof', ['proof' => UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf')])->assertOk();
        $this->assertStringNotContainsString('PRIVATE_DRAFT_CANARY', $response->getContent());
        $second = $this->participation($owner, $post);
        $response = $this->postJson('/api/donation-participations/'.$second->id.'/cancel')->assertOk();
        $this->assertStringNotContainsString('PRIVATE_DRAFT_CANARY', $response->getContent());
        $response->assertJsonPath('participation.status', 'cancelled');

        Http::assertNothingSent();
    }
}
