<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\Notification;
use App\Models\User;
use App\Services\DonationReminderService;
use App\Services\PushNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonationReminderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        $this->travelTo(now()->startOfDay()->addHours(12));
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.fcm.enabled' => false, 'app.calendar_timezone' => 'Asia/Manila']);
    }

    private function activity(string $status = 'pending', int $days = 1): DonationParticipation
    {
        $user = User::factory()->create();
        $opportunity = DonationOpportunity::create(['title' => 'Tomorrow drive', 'description' => 'Test', 'location' => 'Center',
            'event_date' => now()->addDays($days)->toDateString(), 'start_time' => '09:00:00', 'points_reward' => 300,
            'status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => now()->addDays(4)]);
        $item = new DonationParticipation;
        $item->forceFill(['user_id' => $user->id, 'donation_opportunity_id' => $opportunity->id,
            'source_type' => 'admin_announcement', 'status' => $status, 'joined_at' => now()])->saveQuietly();

        return $item;
    }

    public function test_tomorrow_reminder_is_owned_deduplicated_and_available_without_push(): void
    {
        $item = $this->activity();
        $this->artisan('donations:send-reminders')->expectsOutput('Created 1 donation reminder(s).')->assertSuccessful();
        $this->artisan('donations:send-reminders')->expectsOutput('Created 0 donation reminder(s).')->assertSuccessful();
        $notice = Notification::firstOrFail();
        $this->assertSame('donation_reminder', $notice->type);
        $this->assertSame($item->user_id, $notice->user_id);
        $this->assertSame($item->id, $notice->data['participation_id']);
        $this->assertSame(now()->addDay()->toDateString(), $notice->data['event_date']);
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(SendImportantPush::class, 1);
        app(PushNotificationService::class)->send($notice);
        $this->assertSame('not_configured', $notice->fresh()->push_status);
        Http::assertNothingSent();
        $this->getJson('/api/notifications/reminders')->assertUnauthorized();
        $this->withToken($item->user->createToken('test')->plainTextToken);
        $this->getJson('/api/notifications/reminders')->assertOk()->assertJsonCount(1, 'reminders')
            ->assertJsonPath('reminders.0.activity_title', 'Tomorrow drive')->assertJsonPath('reminders.0.start_time', '09:00:00');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('unread_count', 1);
        $this->postJson('/api/notifications/'.$notice->id.'/read')->assertOk();
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function excluded(): array
    {
        return [['pending', 2], ['pending', 0], ['pending', -1], ['completed', 1], ['rejected', 1], ['cancelled', 1]];
    }

    #[DataProvider('excluded')]
    public function test_not_due_or_final_activities_stay_quiet(string $status, int $days): void
    {
        $this->activity($status, $days);
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_all_active_statuses_and_calendar_boundary(): void
    {
        foreach (['pending', 'for_verification', 'needs_revision'] as $status) {
            $this->activity($status);
        }
        $this->travelTo(DonationReminderService::calendarNow()->endOfDay());
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 3);
        $this->travelTo(now()->addSecond());
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 3);
        $notice = Notification::firstOrFail();
        $this->withToken($notice->user->createToken('test')->plainTextToken);
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        app(PushNotificationService::class)->send($notice);
        $this->assertSame('not_due', $notice->fresh()->push_status);
    }

    public function test_expired_unpublished_and_undated_system_option_are_excluded(): void
    {
        $expired = $this->activity();
        $expired->opportunity->update(['expires_at' => now()->subSecond()]);
        $draft = $this->activity();
        $draft->opportunity->update(['status' => 'draft']);
        $system = $this->activity();
        $system->forceFill(['donation_opportunity_id' => null, 'source_type' => 'red_cross_dagupan'])->saveQuietly();
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_popup_rechecks_status_date_expiry_and_owner_without_deleting_history(): void
    {
        $item = $this->activity();
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $this->withToken(User::factory()->create()->createToken('foreign')->plainTextToken);
        $this->getJson('/api/notifications/reminders?user_id='.$item->user_id)->assertJsonCount(0, 'reminders');
        $this->withToken($item->user->createToken('owner')->plainTextToken);
        foreach (['completed', 'rejected', 'cancelled'] as $status) {
            $item->forceFill(['status' => $status])->saveQuietly();
            $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        }
        $item->forceFill(['status' => 'pending'])->saveQuietly();
        $item->opportunity->update(['event_date' => now()->addDays(2)]);
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        $item->opportunity->update(['event_date' => now()->addDay(), 'expires_at' => now()->subSecond()]);
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_reminders_use_configured_local_calendar_and_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T16:30:00Z'));
        $item = $this->activity();
        $item->opportunity->update(['event_date' => '2026-09-23']);
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $notice = Notification::firstOrFail();
        $this->assertSame('2026-09-22', $notice->data['reminder_date']);
        $this->assertSame('2026-09-23', $notice->data['event_date']);
        $this->withToken($item->user->createToken('test')->plainTextToken);
        $this->getJson('/api/notifications/reminders')->assertJsonCount(1, 'reminders')
            ->assertJsonPath('reminders.0.remaining_seconds', 84600);
        config(['app.calendar_timezone' => 'UTC']);
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        $this->assertFalse(app(DonationReminderService::class)->due()->whereKey($item->id)->exists());
        config(['app.calendar_timezone' => 'Asia/Manila']);
        $this->travelTo(CarbonImmutable::parse('2026-09-22T16:00:00Z'));
        $this->getJson('/api/notifications/reminders')->assertJsonCount(0, 'reminders');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_failed_push_and_queue_dispatch_leave_inbox_saved(): void
    {
        $this->activity();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        $this->artisan('donations:send-reminders')->assertSuccessful();
        $notice = Notification::firstOrFail();
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'test', 'services.fcm.credentials' => __FILE__]);
        $sender = new class extends PushNotificationService
        {
            protected function accessToken(): string
            {
                throw new \RuntimeException('Provider unavailable');
            }
        };
        $sender->send($notice);
        $this->assertSame('failed', $notice->fresh()->push_status);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertDatabaseCount('notifications', 1);
        Http::assertNothingSent();
    }
}
