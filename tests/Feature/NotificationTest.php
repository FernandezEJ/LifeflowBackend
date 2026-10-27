<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\DeviceToken;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    private QueueManager $realQueue;

    // ========================================
    // ISOLATED NOTIFICATION TEST DATABASE
    // Never touches real donor history or sends requests to Firebase.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        Http::preventStrayRequests();
        config(['services.fcm.enabled' => false, 'services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
    }

    // ========================================
    // AUTHENTICATION BOUNDARIES
    // Every inbox/device route requires a real Sanctum login.
    // ========================================
    public static function privateRoutes(): array
    {
        return [
            ['get', '/api/notifications'], ['get', '/api/notifications/unread-count'],
            ['post', '/api/notifications/1/read'], ['post', '/api/notifications/read-all'],
            ['post', '/api/device-tokens'], ['post', '/api/device-tokens/unregister'],
        ];
    }

    #[DataProvider('privateRoutes')]
    public function test_guest_returns_401(string $method, string $path): void
    {
        $this->{$method.'Json'}($path)->assertUnauthorized();
    }

    public function test_registration_rotation_transfer_and_logout_preserve_other_devices(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('one'))->assertOk()->assertJsonPath('registered', true);
        $row = DeviceToken::firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotNull($row->last_seen_at);
        $this->postJson('/api/device-tokens', $this->tokenPayload('one'))->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->postJson('/api/device-tokens', $this->tokenPayload('rotated'))->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseMissing('device_tokens', ['token' => $this->tokenPayload('one')['token']]);

        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('second-device')->plainTextToken);
        $this->postJson('/api/device-tokens', $this->tokenPayload('second'))->assertOk();
        $this->assertDatabaseCount('device_tokens', 2);
        $this->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);

        $this->app['auth']->forgetGuards();
        $other = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('rotated'))->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertSame($other->id, DeviceToken::firstOrFail()->user_id);
    }

    public function test_device_validation_and_foreign_unregister(): void
    {
        $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('owned'))->assertOk();
        $this->postJson('/api/device-tokens', [...$this->tokenPayload('owned'), 'user_id' => 999])->assertUnprocessable();
        $this->postJson('/api/device-tokens', [...$this->tokenPayload('owned'), 'platform' => 'ios'])->assertUnprocessable();
        $this->postJson('/api/device-tokens', [...$this->tokenPayload('owned'), 'provider' => 'expo'])->assertUnprocessable();
        $this->postJson('/api/device-tokens', [...$this->tokenPayload('owned'), 'token' => 'short'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->signIn();
        $this->postJson('/api/device-tokens/unregister', ['token' => $this->tokenPayload('owned')['token']])->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->postJson('/api/device-tokens', $this->tokenPayload('mine'))->assertOk();
        $this->postJson('/api/device-tokens/unregister', ['token' => $this->tokenPayload('mine')['token']])->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
    }

    #[DataProvider('transferOwners')]
    public function test_older_login_cannot_reclaim_or_unregister_a_transferred_token(bool $sameOwner): void
    {
        $user = $this->signIn();
        $oldLogin = $user->tokens()->firstOrFail();
        $oldBearer = $this->defaultHeaders['Authorization'];
        $payload = $this->tokenPayload('transferred');
        $this->postJson('/api/device-tokens', $payload)->assertOk();

        $this->app['auth']->forgetGuards();
        $nextUser = $sameOwner ? $user : User::factory()->create();
        $newLogin = $nextUser->createToken('new-login');
        $this->withToken($newLogin->plainTextToken);
        $this->postJson('/api/device-tokens', $payload)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', $oldBearer);
        $this->postJson('/api/device-tokens', $payload)->assertConflict();
        $this->postJson('/api/device-tokens/unregister', ['token' => $payload['token']])->assertOk();
        $this->assertSame($newLogin->accessToken->id, DeviceToken::firstOrFail()->personal_access_token_id);
        $this->assertNotSame($oldLogin->id, DeviceToken::firstOrFail()->personal_access_token_id);
        $this->assertSame($nextUser->id, DeviceToken::firstOrFail()->user_id);
    }

    public static function transferOwners(): array
    {
        return ['same-donor-new-login' => [true], 'different-donor' => [false]];
    }

    public function test_push_job_timeout_is_below_database_reservation_with_margin_and_single_attempt(): void
    {
        $job = new SendImportantPush(1);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThanOrEqual($job->timeout + 30, config('queue.connections.database.retry_after'));
    }

    #[DataProvider('deliveryFailures')]
    public function test_failed_or_ambiguous_delivery_is_not_replayed_and_inbox_is_preserved(?int $status): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('failure'))->assertOk();
        $notice = $this->history($user, 'failure-kind');
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        $attempts = 0;
        Http::fake(function () use (&$attempts, $status) {
            $attempts++;

            return $status === null ? Http::failedConnection() : Http::response([], $status);
        });
        $sender = $this->fakeSender();
        (new SendImportantPush($notice->id))->handle($sender);
        (new SendImportantPush($notice->id))->handle($sender);
        $this->assertSame('failed', $notice->fresh()->push_status);
        $this->assertNotNull($notice->fresh()->push_attempted_at);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertSame(1, $attempts);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 1);
        if ($status !== null) {
            Http::assertSentCount(1);
        }
    }

    public static function deliveryFailures(): array
    {
        return ['invalid-payload' => [400], 'unauthorized' => [401], 'forbidden' => [403],
            'rate-limited' => [429], 'unavailable' => [503], 'ambiguous-network-failure' => [null]];
    }

    public function test_atomic_attempt_claim_blocks_a_second_worker_while_first_send_is_in_progress(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('overlap'))->assertOk();
        $notice = $this->history($user, 'overlap');
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        $sender = $this->fakeSender();
        Http::fake(function () use ($sender, $notice) {
            $sender->send($notice->fresh());

            return Http::response(['name' => 'test-message']);
        });
        $sender->send($notice);
        Http::assertSentCount(1);
        $this->assertSame('sent', $notice->fresh()->push_status);
        $this->assertDatabaseCount('notifications', 1);
    }

    // ========================================
    // PRIVATE HISTORY AND CONFIRMED READ STATE
    // Pagination, filtering and count never include another user's rows.
    // ========================================
    public function test_inbox_pagination_ownership_reads_and_count(): void
    {
        $user = $this->signIn();
        $foreign = $this->history(User::factory()->create(), 'foreign');
        for ($i = 0; $i < 22; $i++) {
            $last = $this->history($user, 'event-'.$i);
        }
        $this->getJson('/api/notifications?user_id='.$foreign->user_id)->assertOk()
            ->assertJsonPath('total', 22)->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $last->id)
            ->assertJsonMissingPath('data.0.event_key')->assertJsonMissingPath('data.0.push_status');
        $this->getJson('/api/notifications?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 22);
        $this->postJson('/api/notifications/'.$foreign->id.'/read')->assertNotFound();
        $this->postJson('/api/notifications/'.$last->id.'/read')->assertOk();
        $readAt = $last->fresh()->read_at->toDateTimeString();
        $this->postJson('/api/notifications/'.$last->id.'/read')->assertOk();
        $this->assertSame($readAt, $last->fresh()->read_at->toDateTimeString());
        $this->getJson('/api/notifications?unread=1')->assertJsonPath('total', 21);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 21);
        $this->postJson('/api/notifications/read-all', ['user_id' => $foreign->user_id])->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 0);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame(['participation_id' => 1], $last->data);
    }

    // ========================================
    // IMPORTANT-ONLY AUTOMATIC HOOKS AND DEDUPLICATION
    // Retries and ordinary changes produce no repeated or low-value alerts.
    // ========================================
    public function test_outcomes_are_automatic_deduplicated_and_rejection_is_private(): void
    {
        $user = $this->signIn();
        $item = $this->participation($user);
        foreach (['for_verification', 'cancelled', 'pending'] as $status) {
            $item->status = $status;
            $item->save();
            $this->assertNull(app(NotificationService::class)->notifyParticipationOutcome($item));
        }
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
        $item->status = 'completed';
        $item->save();
        $item->save();
        app(NotificationService::class)->notifyParticipationOutcome($item);
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(SendImportantPush::class, 1);
        $notice = Notification::firstOrFail();
        $this->assertSame('donation_completed', $notice->type);
        $this->assertSame('Donation verified; 300 points were added.', $notice->message);
        $this->assertSame($item->id, $notice->data['participation_id']);

        $rejected = $this->participation($user);
        $rejected->status = 'rejected';
        $rejected->rejection_reason = 'Private medical reason';
        $rejected->save();
        $rejected->save();
        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(SendImportantPush::class, 2);
        $notice = Notification::latest('id')->firstOrFail();
        $this->assertSame('donation_rejected', $notice->type);
        $this->assertStringContainsString('Private medical reason', $notice->message);
        $this->assertDatabaseCount('donation_records', 0);
    }

    public function test_revision_is_owned_readable_and_deduplicated_per_proof(): void
    {
        $user = $this->signIn();
        $foreign = User::factory()->create();
        $item = $this->participation($user);
        $item->forceFill(['status' => 'needs_revision', 'revision_reason' => 'Please upload a clearer proof.', 'proof_path' => 'first-proof.jpg'])->save();
        $item->save();
        app(NotificationService::class)->notifyParticipationOutcome($item);
        $notice = Notification::firstOrFail();
        $this->assertSame('donation_needs_revision', $notice->type);
        $this->assertSame($user->id, $notice->user_id);
        $this->assertSame(0, $foreign->notifications()->count());
        $this->assertSame($item->id, $notice->data['participation_id']);
        $this->assertStringContainsString('Please upload a clearer proof.', $notice->message);
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(SendImportantPush::class, 1);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 1);
        $this->getJson('/api/notifications')->assertJsonPath('data.0.id', $notice->id)->assertJsonPath('data.0.type', 'donation_needs_revision');
        $this->postJson('/api/notifications/'.$notice->id.'/read')->assertOk()->assertJsonPath('notification.data.participation_id', $item->id);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 0);
        config(['services.fcm.enabled' => false]);
        app(PushNotificationService::class)->send($notice);
        $this->assertSame('not_configured', $notice->fresh()->push_status);
        $this->assertDatabaseCount('notifications', 1);
        Http::assertNothingSent();
        $item->forceFill(['status' => 'for_verification', 'proof_path' => 'replacement-proof.jpg', 'revision_reason' => null])->save();
        $this->assertDatabaseCount('notifications', 1);
        $item->forceFill(['status' => 'needs_revision', 'revision_reason' => null])->save();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame('Please update your donation proof.', Notification::latest('id')->firstOrFail()->message);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_review_reasons_stay_in_inbox_when_push_fails(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('review'))->assertOk();
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        Http::fake(['https://fcm.googleapis.com/*' => Http::response([], 503)]);
        foreach (['rejected', 'needs_revision'] as $status) {
            $item = $this->participation($user);
            $item->forceFill(['status' => $status, 'rejection_reason' => 'Private reason', 'revision_reason' => str_repeat('Private reason ', 40)])->save();
            $notice = Notification::latest('id')->firstOrFail();
            $this->fakeSender()->send($notice);
            $this->assertSame('failed', $notice->fresh()->push_status);
            $this->assertNull($notice->fresh()->read_at);
            $this->assertStringContainsString('Private reason', $notice->message);
            $this->assertLessThanOrEqual(100, mb_strlen($notice->message));
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['message']['notification']['body'] === 'Your donation proof was rejected; check the reason.');
        Http::assertSent(fn ($request) => $request['message']['notification']['body'] === 'Please update your donation proof.');
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('Private reason', $request->body());
        }
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_rollback_creates_no_history_or_push_job(): void
    {
        $item = $this->participation(User::factory()->create());
        DB::beginTransaction();
        $item->status = 'completed';
        $item->save();
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('pending', $item->fresh()->status);
        // Queue fake stores afterCommit intent; the real database queue is
        // exercised separately below to verify rollback publication behavior.
    }

    public function test_real_database_queue_does_not_publish_rolled_back_push(): void
    {
        Queue::swap($this->realQueue);
        config(['queue.default' => 'database']);
        $item = $this->participation(User::factory()->create());
        DB::beginTransaction();
        $item->status = 'completed';
        $item->save();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_future_admin_hook_requires_important_active_publication(): void
    {
        User::factory()->count(2)->create();
        $opportunity = $this->opportunity();
        $service = app(NotificationService::class);
        $service->notifyImportantAnnouncement($opportunity);
        $this->assertDatabaseCount('notifications', 0);
        $opportunity->status = 'draft';
        $opportunity->save();
        $service->notifyImportantAnnouncement($opportunity, true);
        $this->assertDatabaseCount('notifications', 0);
        $opportunity->status = 'published';
        $opportunity->save();
        $service->notifyImportantAnnouncement($opportunity, true);
        $service->notifyImportantAnnouncement($opportunity, true);
        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(SendImportantPush::class, 2);
        $this->assertSame('admin_announcement', Notification::firstOrFail()->type);
    }

    public function test_donor_join_and_proof_upload_are_quiet(): void
    {
        $user = $this->signIn();
        $user->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $id = $this->postJson('/api/donation-opportunities/'.$this->opportunity()->id.'/join')->assertCreated()->json('participation.id');
        Storage::fake('proofs');
        $this->postJson('/api/donation-participations/'.$id.'/proof', ['proof' => UploadedFile::fake()->create('proof.jpg', 1, 'image/jpeg')])->assertOk();
        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/donation-participations/'.$id)->assertOk();
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    // ========================================
    // MOCKED FCM DELIVERY AND INVALID TOKEN CLEANUP
    // No real service-account key or phone delivery is used in tests.
    // ========================================
    public function test_missing_credentials_preserve_inbox_without_network(): void
    {
        $notice = $this->history(User::factory()->create(), 'missing-config');
        app(PushNotificationService::class)->send($notice);
        Http::assertNothingSent();
        $this->assertSame('not_configured', $notice->fresh()->push_status);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_fcm_payload_deduplication_and_unregistered_token_cleanup(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('fcm'))->assertOk();
        $notice = $this->history($user, 'send-once');
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        Http::fake(['https://fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/test/messages/1'])->push(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)]);
        $sender = $this->fakeSender();
        $sender->send($notice);
        $sender->send($notice);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['message']['data']['notification_id'] === (string) $notice->id
            && json_decode($request['message']['data']['body'], true)['notification_id'] === (string) $notice->id
            && $request['message']['android']['notification']['channel_id'] === 'important');
        $this->assertSame('sent', $notice->fresh()->push_status);

        $sender->send($this->history($user, 'invalid-token'));
        $this->assertDatabaseCount('device_tokens', 0);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_fcm_failure_does_not_delete_valid_token_or_repeat_push(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('retry'))->assertOk();
        $notice = $this->history($user, 'failure');
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        Http::fake(['https://fcm.googleapis.com/*' => Http::response([], 503)]);
        $sender = $this->fakeSender();
        $sender->send($notice);
        $sender->send($notice);
        Http::assertSentCount(1);
        $this->assertSame('failed', $notice->fresh()->push_status);
        $this->assertDatabaseCount('device_tokens', 1);
    }

    public function test_non_approved_type_and_expired_session_cannot_send(): void
    {
        $user = $this->signIn();
        $this->postJson('/api/device-tokens', $this->tokenPayload('expired'))->assertOk();
        DeviceToken::firstOrFail()->accessToken->update(['expires_at' => now()->subMinute()]);
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test-project', 'services.fcm.credentials' => __FILE__]);
        $notice = $this->history($user, 'expired');
        $this->fakeSender()->send($notice);
        $this->assertSame('no_devices', $notice->fresh()->push_status);
        $notice = $this->history($user, 'minor');
        $notice->type = 'profile_updated';
        $notice->save();
        $this->fakeSender()->send($notice);
        Http::assertNothingSent();
    }

    // ========================================
    // LOCAL TEST FIXTURES
    // Each test owns its data and token; no production announcements are made.
    // ========================================
    public function test_queue_outage_preserves_outcomes_award_and_announcement_history(): void
    {
        $user = User::factory()->create();
        Bus::shouldReceive('dispatch')->times(4)->andThrow(new \RuntimeException('Queue unavailable'));
        foreach (['completed', 'rejected', 'needs_revision'] as $status) {
            $item = $this->participation($user);
            $item->status = $status;
            DB::transaction(fn () => $item->save());
            $this->assertSame($status, $item->fresh()->status);
            app(NotificationService::class)->notifyParticipationOutcome($item);
        }
        $opportunity = $this->opportunity();
        app(NotificationService::class)->notifyImportantAnnouncement($opportunity, true);
        app(NotificationService::class)->notifyImportantAnnouncement($opportunity, true);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame(300, (int) $user->pointTransactions()->sum('amount'));
        $this->assertSame(4, $user->unreadNotifications()->count());
        Http::assertNothingSent();
    }

    public function test_six_existing_categories_use_concise_copy_and_preserve_existing_history(): void
    {
        $user = User::factory()->create();
        $legacy = $this->history($user, 'retained-before-branding');
        $before = $legacy->refresh()->getAttributes();
        $service = app(NotificationService::class);
        foreach (['completed', 'rejected', 'needs_revision'] as $status) {
            $item = $this->participation($user);
            $item->forceFill(['status' => $status, 'rejection_reason' => 'Private note.', 'revision_reason' => 'Private note.'])->saveQuietly();
            $service->notifyParticipationOutcome($item, $status === 'completed' ? 300 : null);
            $service->notifyParticipationOutcome($item, $status === 'completed' ? 300 : null);
        }
        $opportunity = $this->opportunity();
        $service->notifyImportantAnnouncement($opportunity, true);
        $service->notifyImportantAnnouncement($opportunity, true);
        $reminder = $this->participation($user);
        $service->notifyDonationReminder($reminder);
        $service->notifyDonationReminder($reminder);
        $rest = $this->participation($user);
        $rest->forceFill(['status' => 'completed'])->saveQuietly();
        $metadata = ['last_completed_donation_at' => now()->subMonths(3)->toIso8601String(), 'next_eligible_donation_at' => now()->toIso8601String()];
        $service->notifyDonationCooldownComplete($user, $rest, $metadata, 'copy:cooldown');
        $service->notifyDonationCooldownComplete($user, $rest, $metadata, 'copy:cooldown');
        $expected = [
            'admin_announcement' => ['Important Announcement', 'A new LifeFlow announcement is available.'],
            'donation_completed' => ['Donation Verified', 'Donation verified; 300 points were added.'],
            'donation_rejected' => ['Proof Rejected', 'Your donation proof was rejected; check the reason. Private note.'],
            'donation_needs_revision' => ['Proof Needs Revision', 'Please update your donation proof. Private note.'],
            'donation_reminder' => ['Donation Tomorrow', 'Your joined donation activity is tomorrow.'],
            'donation_cooldown_complete' => ['Donation Rest Complete', 'Your rest period is complete; take a self-assessment before donating.'],
        ];
        $this->assertSame(Notification::TYPES, array_keys($expected));
        $notices = Notification::where('id', '!=', $legacy->id)->get()->keyBy('type');
        $this->assertCount(6, $notices);
        foreach ($expected as $type => [$title, $body]) {
            $notice = $notices[$type];
            $this->assertSame($title, $notice->title);
            $this->assertSame($body, $notice->message);
            $this->assertLessThanOrEqual(50, mb_strlen($notice->title));
            $this->assertLessThanOrEqual(100, mb_strlen($notice->message));
            $this->assertNull($notice->read_at);
            $this->assertSame($user->id, $notice->user_id);
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 7);
        Queue::assertPushed(SendImportantPush::class, 6);
        Http::assertNothingSent();
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function tokenPayload(string $suffix): array
    {
        return ['token' => 'test-only-device-token-'.$suffix, 'platform' => 'android', 'provider' => 'fcm'];
    }

    private function opportunity(): DonationOpportunity
    {
        return DonationOpportunity::create(['title' => 'Test drive', 'description' => 'Test', 'location' => 'Center',
            'event_date' => now()->toDateString(), 'points_reward' => 300, 'status' => 'published',
            'published_at' => now()->subHour(), 'expires_at' => now()->addDay()]);
    }

    private function participation(User $user): DonationParticipation
    {
        $item = new DonationParticipation;
        $item->user_id = $user->id;
        $item->donation_opportunity_id = $this->opportunity()->id;
        $item->status = 'pending';
        $item->joined_at = now();
        $item->save();

        return $item;
    }

    private function history(User $user, string $event): Notification
    {
        return $user->notifications()->create(['type' => 'donation_completed', 'title' => 'Donation verified',
            'message' => 'Your donation has been verified successfully.', 'data' => ['participation_id' => 1], 'event_key' => $event]);
    }

    private function fakeSender(): PushNotificationService
    {
        return new class extends PushNotificationService
        {
            protected function accessToken(): string
            {
                return 'test-only-oauth';
            }
        };
    }
}
