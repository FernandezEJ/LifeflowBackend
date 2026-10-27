<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationAnnouncementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T15:59:00Z'));
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.fcm.enabled' => false, 'app.calendar_timezone' => 'Asia/Manila']);
    }

    private function publication(array $overrides = []): DonationOpportunity
    {
        return DonationOpportunity::create(array_replace([
            'title' => 'Retained community drive', 'description' => 'Original donation information',
            'location' => 'Community Center', 'event_date' => '2026-10-28', 'points_reward' => 300,
            'source_type' => 'admin_announcement', 'status' => 'published',
            'published_at' => now()->subDay(), 'expires_at' => now()->addMinute(),
        ], $overrides));
    }

    private function notice(User $user, DonationOpportunity $item): Notification
    {
        return $user->notifications()->create(['type' => 'admin_announcement', 'title' => 'Important announcement',
            'message' => 'Open announcement details', 'event_key' => 'announcement:'.$item->id,
            'data' => ['opportunity_id' => $item->id]]);
    }

    private function signIn(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('notification-test')->plainTextToken);
    }

    public function test_expired_notice_returns_retained_rich_content_without_mutating_history_or_read_state(): void
    {
        $owner = User::factory()->create();
        $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Bring ID', 'bold' => true]]]]];
        $item = $this->publication(['expires_at' => now()->subSecond(), 'description_document' => $document]);
        $notice = $this->notice($owner, $item);
        $this->signIn($owner);
        $response = $this->getJson('/api/notifications/'.$notice->id.'/announcement')->assertOk()
            ->assertJsonPath('opportunity.title', $item->title)->assertJsonPath('opportunity.location', $item->location)
            ->assertJsonPath('opportunity.description_document', $document)->assertJsonPath('view_state', 'expired')
            ->assertJsonPath('read_only', true)->assertJsonMissingPath('opportunity.image_path')->assertJsonMissingPath('opportunity.created_by');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertNull($notice->fresh()->read_at);
        $this->assertSame('published', $item->fresh()->status);
        $this->assertDatabaseCount('donation_participations', 0);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->getJson('/api/announcements/'.$item->id)->assertNotFound();
        $this->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $owner->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $this->postJson('/api/donation-opportunities/'.$item->id.'/join')->assertNotFound();
        $this->assertDatabaseCount('donation_participations', 0);
        $this->postJson('/api/notifications/'.$notice->id.'/read')->assertOk();
        $this->getJson('/api/notifications/'.$notice->id.'/announcement')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 0);
        $item->update(['title' => 'Retained edited title']);
        $this->getJson('/api/notifications/'.$notice->id.'/announcement')->assertJsonPath('opportunity.title', 'Retained edited title');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_authentication_ownership_linked_ids_and_notification_type_are_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = $this->publication(['expires_at' => now()->subDay()]);
        $notice = $this->notice($owner, $item);
        $url = '/api/notifications/'.$notice->id.'/announcement';
        $this->getJson($url)->assertUnauthorized();
        $this->signIn($other);
        $this->getJson($url.'?user_id='.$owner->id.'&opportunity_id='.$item->id)->assertNotFound();
        $this->getJson('/api/announcements/'.$item->id)->assertNotFound();
        $this->getJson('/api/notifications/999999/announcement')->assertNotFound();
        $this->signIn($owner);
        $unlinked = $this->publication(['title' => 'Unlinked private draft', 'status' => 'draft']);
        $this->getJson($url.'?opportunity_id='.$unlinked->id)->assertOk()->assertJsonPath('opportunity.id', $item->id);
        $notice->update(['type' => 'donation_reminder']);
        $this->getJson($url)->assertNotFound();
        $notice->update(['type' => 'admin_announcement', 'data' => ['opportunity_id' => '../'.$item->id]]);
        $this->getJson($url)->assertNotFound();
        $notice->update(['data' => ['opportunity_id' => $item->id]]);
        $staff = User::factory()->create(['role' => 'admin']);
        $this->signIn($staff);
        $this->getJson($url)->assertForbidden();
        $owner->forceFill(['deactivated_at' => now()])->save();
        $this->signIn($owner);
        $this->getJson($url)->assertForbidden();
    }

    public static function inaccessible(): array
    {
        return [['draft'], ['cancelled'], ['expired'], ['unpublished'], ['future_publication'], ['no_expiration'], ['deleted'], ['purged'], ['permanent_source']];
    }

    #[DataProvider('inaccessible')]
    public function test_a_notice_does_not_restore_deliberately_inaccessible_content(string $state): void
    {
        $owner = User::factory()->create();
        $item = $this->publication(['expires_at' => now()->subSecond()]);
        $notice = $this->notice($owner, $item);
        match ($state) {
            'draft', 'cancelled', 'expired' => $item->update(['status' => $state]),
            'unpublished' => $item->update(['published_at' => null]),
            'future_publication' => $item->update(['published_at' => now()->addDay()]),
            'no_expiration' => $item->update(['expires_at' => null]),
            'deleted' => $item->delete(),
            'purged' => $item->forceDelete(),
            'permanent_source' => $item->update(['source_type' => 'red_cross_dagupan']),
        };
        $this->signIn($owner);
        $this->getJson('/api/notifications/'.$notice->id.'/announcement')->assertNotFound();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_expiration_uses_the_publication_timestamp_instead_of_the_event_date_or_device_calendar(): void
    {
        $owner = User::factory()->create();
        $item = $this->publication(['event_date' => '2026-10-01']);
        $notice = $this->notice($owner, $item);
        $this->signIn($owner);
        $url = '/api/notifications/'.$notice->id.'/announcement';
        $this->getJson($url)->assertJsonPath('view_state', 'active')->assertJsonPath('read_only', false);
        $this->getJson('/api/announcements/'.$item->id)->assertOk()->assertJsonStructure(['server_time']);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T16:00:00Z'));
        $this->getJson($url)->assertJsonPath('view_state', 'expired')->assertJsonPath('read_only', true);
        $item->update(['event_date' => '2026-11-01']);
        $this->getJson($url)->assertJsonPath('read_only', true);
        $this->getJson('/api/announcements/'.$item->id)->assertNotFound();
    }

    public function test_expired_cover_uses_existing_private_r2_signing_and_missing_cover_fallback(): void
    {
        config(['filesystems.disks.r2_media.key' => 'dummy', 'filesystems.disks.r2_media.secret' => 'dummy',
            'filesystems.disks.r2_media.endpoint' => 'https://example.r2.cloudflarestorage.com', 'filesystems.disks.r2_media.bucket' => 'lifeflow-media']);
        $disk = Storage::fake('r2_media');
        $disk->buildTemporaryUrlsUsing(fn () => 'https://signed.test/retained-announcement.png');
        $key = 'announcements/11111111-1111-4111-8111-111111111111.png';
        $disk->put($key, 'fake-image');
        $owner = User::factory()->create();
        $item = $this->publication(['expires_at' => now()->subSecond(), 'image_path' => 'r2_media:'.$key]);
        $notice = $this->notice($owner, $item);
        $this->signIn($owner);
        $url = '/api/notifications/'.$notice->id.'/announcement';
        $this->getJson($url)->assertOk()->assertJsonPath('opportunity.image_url', 'https://signed.test/retained-announcement.png');
        $disk->delete($key);
        $this->getJson($url)->assertOk()->assertJsonPath('opportunity.image_url', null);
        $this->assertDatabaseCount('donation_participations', 0);
        Http::assertNothingSent();
    }
}
