<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\AuditLog;
use App\Models\DonationOpportunity;
use App\Models\User;
use App\Services\Admin\AnnouncementService;
use App\Services\DonationReminderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-09-27 05:00:00');
    }

    private function signIn(string $role = 'admin', array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, ...$attributes]);
        $this->withToken($user->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['title' => 'Community blood drive', 'description' => 'Please join our donation event.',
            'location' => 'Dagupan', 'donation_date' => '2026-10-01', 'expires_at' => '2026-10-01'], $changes);
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'super admin' => ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_both_roles_can_manage_full_lifecycle_and_record_audits(string $role): void
    {
        Queue::fake();
        $actor = $this->signIn($role);
        $id = $this->postJson('/api/admin/announcements', $this->payload())->assertCreated()
            ->assertJsonPath('announcement.status', 'draft')->json('announcement.id');
        $this->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->getJson('/api/announcements/'.$id)->assertNotFound();
        $this->putJson('/api/admin/announcements/'.$id, $this->payload(['title' => 'Edited drive']))->assertOk()->assertJsonPath('announcement.title', 'Edited drive');
        $this->postJson('/api/admin/announcements/'.$id.'/publish')->assertOk()->assertJsonPath('announcement.status', 'active');
        $this->getJson('/api/announcements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_type', 'admin_announcement');
        $this->postJson('/api/admin/announcements/'.$id.'/draft')->assertOk()->assertJsonPath('announcement.status', 'draft');
        $this->deleteJson('/api/admin/announcements/'.$id)->assertOk()->assertJsonPath('announcement.status', 'deleted');
        $this->assertSoftDeleted('donation_opportunities', ['id' => $id]);
        $this->getJson('/api/announcements/'.$id)->assertNotFound();
        $this->postJson('/api/admin/announcements/'.$id.'/publish')->assertNotFound();
        $this->postJson('/api/admin/announcements/'.$id.'/restore')->assertOk()->assertJsonPath('announcement.status', 'draft')->assertJsonPath('announcement.published_at', null);
        $this->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/announcements/'.$id)->assertOk()->assertJsonPath('announcement.title', 'Edited drive');
        $this->assertDatabaseHas('donation_opportunities', ['id' => $id, 'created_by' => $actor->id, 'deleted_at' => null]);
        $this->assertSame(['announcement_created', 'announcement_updated', 'announcement_published', 'announcement_moved_to_draft', 'announcement_deleted', 'announcement_restored'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(0, AuditLog::whereNotNull('details')->count());
        Queue::assertNothingPushed();
    }

    public static function deniedRoles(): array
    {
        return ['guest' => [null, [], 401], 'donor' => ['donor', [], 403],
            'password change' => ['admin', ['must_change_password' => true], 403],
            'deactivated' => ['admin', ['deactivated_at' => '2026-09-26'], 403]];
    }

    #[DataProvider('deniedRoles')]
    public function test_all_management_actions_reject_unqualified_accounts(?string $role, array $attributes, int $status): void
    {
        if ($role) {
            $this->signIn($role, $attributes);
        }
        foreach ([['GET', ''], ['POST', ''], ['GET', '/1'], ['PUT', '/1'], ['DELETE', '/1'],
            ['POST', '/1/publish'], ['POST', '/1/draft'], ['POST', '/1/restore']] as [$method, $suffix]) {
            $this->json($method, '/api/admin/announcements'.$suffix, $this->payload())->assertStatus($status);
        }
        $this->assertDatabaseCount('donation_opportunities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_publish_notifies_only_donors_once_and_expiry_hides_post(): void
    {
        Queue::fake();
        $donor = User::factory()->create();
        $this->signIn();
        $id = $this->postJson('/api/admin/announcements', $this->payload(['status' => 'published']))->assertCreated()->json('announcement.id');
        $this->postJson('/api/admin/announcements/'.$id.'/publish')->assertOk();
        $this->putJson('/api/admin/announcements/'.$id, $this->payload(['status' => 'published']))->assertOk();
        $this->postJson('/api/admin/announcements/'.$id.'/draft')->assertOk();
        $this->postJson('/api/admin/announcements/'.$id.'/publish')->assertOk();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $donor->id, 'type' => 'admin_announcement']);
        Queue::assertPushed(SendImportantPush::class, 1);
        $this->getJson('/api/announcements/'.$id)->assertOk()->assertJsonPath('opportunity.donation_date', '2026-10-01');
        $this->travelTo('2026-10-01 16:00:00');
        $this->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/announcements?status=expired')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertOk()->assertJsonPath('opportunity.source_type', 'red_cross_dagupan');
    }

    public function test_push_dispatch_failure_does_not_undo_publishing(): void
    {
        User::factory()->create();
        $this->signIn();
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->postJson('/api/admin/announcements', $this->payload(['status' => 'published']))->assertCreated()->assertJsonPath('announcement.status', 'active');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement_published']);
    }

    public static function invalidData(): array
    {
        return ['title' => [['title' => ''], 'title'], 'description' => [['description' => ''], 'description'],
            'date' => [['donation_date' => 'invalid'], 'donation_date'], 'expiry' => [['expires_at' => '2026-10-02'], 'expires_at'],
            'points' => [['points_reward' => 999], 'points_reward'], 'owner' => [['created_by' => 99], 'created_by'],
            'source' => [['source_type' => 'red_cross_dagupan'], 'source_type'], 'state' => [['status' => 'deleted'], 'status'],
            'expired publish' => [['donation_date' => '2026-09-01', 'expires_at' => '2026-09-01', 'status' => 'published'], 'expires_at']];
    }

    #[DataProvider('invalidData')]
    public function test_invalid_input_does_not_create_post(array $data, string $field): void
    {
        $this->signIn();
        $this->postJson('/api/admin/announcements', $this->payload($data))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('donation_opportunities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_image_upload_replace_and_invalid_files(): void
    {
        Storage::fake('public');
        $this->signIn();
        $result = $this->post('/api/admin/announcements', $this->payload(['image' => UploadedFile::fake()->image('untrusted-name.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonMissingPath('announcement.image_path');
        $id = $result->json('announcement.id');
        $old = DonationOpportunity::findOrFail($id)->image_path;
        Storage::disk('public')->assertExists($old);
        $this->assertStringNotContainsString('untrusted-name', $old);
        $this->assertStringContainsString('/storage/announcements/', $result->json('announcement.image_url'));
        $this->post('/api/admin/announcements/'.$id, $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('replacement.jpg')]), ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($old);
        $new = DonationOpportunity::findOrFail($id)->image_path;
        Storage::disk('public')->assertExists($new);
        $this->post('/api/admin/announcements', $this->payload(['image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/admin/announcements', $this->payload(['image' => UploadedFile::fake()->image('huge.png')->size(5121)]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson('/api/admin/announcements/'.$id)->assertOk();
        Storage::disk('public')->assertExists($new);
        $this->travel(30)->days();
        $this->artisan('announcements:purge-deleted')->assertExitCode(0);
        Storage::disk('public')->assertMissing($new);
        $this->assertDatabaseMissing('donation_opportunities', ['id' => $id]);
    }

    public function test_filters_search_and_default_expiration(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/admin/announcements', $this->payload(['expires_at' => null]))->assertCreated()
            ->assertJsonPath('announcement.expires_at', '2026-10-01T15:59:59.000000Z')->json('announcement.id');
        $this->getJson('/api/admin/announcements?search=COMMUNITY&month=10&year=2026&status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/announcements?month=9')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/announcements?status=active')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/announcements?search=%27%20OR%201%3D1')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/announcements?month=13')->assertUnprocessable();
        $this->deleteJson('/api/admin/announcements/'.$id)->assertOk();
        $this->getJson('/api/admin/announcements?status=deleted')->assertJsonCount(1, 'data');
    }

    public function test_join_uses_existing_eligibility_and_history_survives_delete_and_purge(): void
    {
        Queue::fake();
        $admin = $this->signIn();
        $id = $this->postJson('/api/admin/announcements', $this->payload(['status' => 'published']))->assertCreated()->json('announcement.id');
        $donor = $this->signIn('donor');
        $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertConflict()->assertJsonPath('reason', 'evaluation_required');
        $assessment = $donor->eligibilityAssessments()->create(['result' => 'not_eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertConflict()->assertJsonPath('reason', 'evaluation_not_eligible');
        $assessment->update(['result' => 'eligible', 'assessed_at' => now()->subDay()]);
        $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertConflict()->assertJsonPath('reason', 'evaluation_required');
        $assessment->update(['assessed_at' => now()]);
        $participation = $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertCreated()->assertJsonPath('participation.status', 'pending')->json('participation.id');
        $this->postJson('/api/donation-opportunities/'.$id.'/join')->assertConflict()->assertJsonPath('reason', 'active_participation_exists');
        app(AnnouncementService::class)->transition($admin, $id, 'delete');
        $this->travelTo('2026-09-30 05:00:00');
        $this->assertSame(0, app(DonationReminderService::class)->due()->count());
        $this->getJson('/api/donation-participations/'.$participation)->assertOk()->assertJsonPath('participation.opportunity.title', 'Community blood drive');
        $this->getJson('/api/donation-participations?status=pending')->assertJsonPath('data.0.id', $participation);
        $this->travel(30)->days();
        $this->assertSame(['purged' => 0, 'retained' => 1], app(AnnouncementService::class)->purge());
        $this->assertDatabaseCount('donation_participations', 1);
        $this->assertDatabaseCount('donation_records', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement_deleted', 'target_id' => $id]);
        Queue::assertNothingPushed();
    }

    public function test_recovery_boundary_and_daily_schedule(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/admin/announcements', $this->payload())->json('announcement.id');
        $this->deleteJson('/api/admin/announcements/'.$id)->assertOk();
        $this->travel(29)->days();
        $this->assertSame(['purged' => 0, 'retained' => 0], app(AnnouncementService::class)->purge());
        $this->travel(1)->days();
        $this->postJson('/api/admin/announcements/'.$id.'/restore')->assertConflict();
        $this->assertSame(['purged' => 1, 'retained' => 0], app(AnnouncementService::class)->purge());
        $this->assertDatabaseCount('audit_logs', 2);
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'announcements:purge-deleted'));
        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertSame('Asia/Manila', $events->first()->timezone);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
