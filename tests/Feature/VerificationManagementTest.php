<?php

namespace Tests\Feature;

use App\Jobs\SendImportantPush;
use App\Models\AuditLog;
use App\Models\DonationParticipation;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Admin\VerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerificationManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-09-27 05:00:00');
        Storage::fake('proofs');
        Queue::fake();
    }

    private function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create(['role' => 'admin']);
        $this->withToken($user->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    private function submission(?User $donor = null, string $status = 'for_verification', string $extension = 'png'): DonationParticipation
    {
        $donor ??= User::factory()->create();
        $item = new DonationParticipation;
        $item->forceFill(['user_id' => $donor->id, 'source_type' => 'red_cross_dagupan', 'status' => 'pending', 'joined_at' => now()])->save();
        $path = 'donation-proofs/'.$donor->id.'/'.$item->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('proofs')->put($path, 'private proof bytes');
        // Arrange historical states without executing the transition being tested.
        DB::table('donation_participations')->where('id', $item->id)->update(['status' => $status, 'proof_path' => $path,
            'proof_mime_type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'pdf' => 'application/pdf'][$extension],
            'proof_size' => 19, 'proof_original_name' => 'certificate.'.$extension, 'proof_uploaded_at' => now()]);

        return $item->refresh();
    }

    private function payload(DonationParticipation $item, array $extra = []): array
    {
        return ['proof_version' => app(VerificationService::class)->version($item), ...$extra];
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_both_roles_can_read_proof_and_approve_once(string $role): void
    {
        $donor = User::factory()->create();
        $item = $this->submission($donor);
        $reviewer = $this->signIn(User::factory()->create(['role' => $role]));
        $this->getJson('/api/admin/verifications')->assertOk()->assertJsonPath('data.0.id', $item->id);
        $detail = $this->getJson('/api/admin/verifications/'.$item->id)->assertOk()->assertJsonPath('verification.donor.id', $donor->id)
            ->assertJsonPath('verification.opportunity.title', 'Philippine Red Cross ? Dagupan City Chapter');
        $this->assertStringNotContainsString($item->proof_path, $detail->getContent());
        $this->get('/api/admin/verifications/'.$item->id.'/proof')->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $this->payload($item))->assertOk()
            ->assertJsonPath('verification.status', 'completed')->assertJsonPath('verification.completion.points_awarded', 300);
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $this->payload($item))->assertConflict();
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $reviewer->id, 'target_id' => $item->id, 'action' => 'verification_approved']);
        $this->assertNotNull($item->refresh()->verified_at);
        Queue::assertPushed(SendImportantPush::class, 1);
        $this->signIn($donor);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 1)->assertJsonPath('achievement.key', 'first_time_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 300);
        $this->getJson('/api/donation-participations/'.$item->id)->assertJsonPath('participation.status', 'completed');
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.type', 'donation_completed');
    }

    public static function denied(): array
    {
        return ['guest' => [null, false, 401], 'donor' => ['donor', false, 403], 'password gate' => ['admin', true, 403]];
    }

    #[DataProvider('denied')]
    public function test_all_endpoints_deny_unqualified_accounts(?string $role, bool $pending, int $status): void
    {
        $item = $this->submission();
        if ($role) {
            $this->signIn(User::factory()->create(['role' => $role, 'must_change_password' => $pending]));
        }
        foreach (['', '/'.$item->id, '/'.$item->id.'/proof'] as $suffix) {
            $this->getJson('/api/admin/verifications'.$suffix)->assertStatus($status);
        }
        foreach (['approve', 'needs-revision', 'reject'] as $action) {
            $this->postJson('/api/admin/verifications/'.$item->id.'/'.$action, $this->payload($item, ['reason' => 'Review note']))->assertStatus($status);
        }
        $this->assertSame('for_verification', $item->refresh()->status);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_tabs_search_and_month_filters_use_real_records_without_proof_paths(): void
    {
        $donor = User::factory()->create(['name' => 'Alice Donor', 'email' => 'alice@example.test']);
        $donor->donorProfile()->create(['first_name' => 'Alice', 'last_name' => 'Donor', 'blood_type' => 'O+',
            'mobile_number' => '09123456789', 'birth_date' => '1995-01-01', 'gender' => 'Female']);
        foreach (['pending', 'for_verification', 'needs_revision', 'completed', 'rejected', 'cancelled'] as $status) {
            $this->submission($donor, $status);
        }
        $this->signIn();
        $response = $this->getJson('/api/admin/verifications')->assertOk()->assertJsonCount(5, 'data');
        $this->assertStringNotContainsString('donation-proofs/', $response->getContent());
        foreach (['pending', 'for_verification', 'needs_revision', 'completed', 'rejected'] as $status) {
            $this->getJson('/api/admin/verifications?status='.$status)->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', $status);
        }
        $this->getJson('/api/admin/verifications?search=ALICE&month=9&year=2026')->assertJsonCount(5, 'data');
        $this->getJson('/api/admin/verifications?search=Dagupan')->assertJsonCount(5, 'data');
        $this->getJson('/api/admin/verifications?search=O%2B')->assertJsonCount(5, 'data');
        $this->getJson('/api/admin/verifications?month=10')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/verifications?search=%27%20OR%201%3D1')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/verifications?status=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/verifications?month=13')->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public static function types(): array
    {
        return [['jpg', 'image/jpeg'], ['png', 'image/png'], ['pdf', 'application/pdf']];
    }

    #[DataProvider('types')]
    public function test_private_proof_stream_has_safe_headers_and_exact_bytes(string $extension, string $mime): void
    {
        $item = $this->submission(extension: $extension);
        $this->signIn();
        $response = $this->get('/api/admin/verifications/'.$item->id.'/proof')->assertOk()->assertHeader('Content-Type', $mime);
        $this->assertSame('private proof bytes', $response->streamedContent());
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('donation-proofs/', implode(' ', $response->headers->all('Content-Disposition')));
        Queue::assertNothingPushed();
    }

    public function test_missing_cross_owner_and_traversal_proofs_are_not_served_or_approved(): void
    {
        $item = $this->submission();
        $this->signIn();
        Storage::disk('proofs')->delete($item->proof_path);
        $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertNotFound();
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $this->payload($item))->assertNotFound();
        foreach (['../secret.pdf', 'donation-proofs/999/'.$item->id.'/'.Str::uuid().'.pdf', 'https://example.test/proof.pdf'] as $path) {
            $item->forceFill(['proof_path' => $path])->save();
            $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertNotFound();
        }
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public static function reasons(): array
    {
        return [['needs-revision', ''], ['reject', ''], ['needs-revision', str_repeat('a', 1001)], ['reject', str_repeat('a', 1001)]];
    }

    #[DataProvider('reasons')]
    public function test_revision_and_rejection_require_bounded_reasons(string $action, string $reason): void
    {
        $item = $this->submission();
        $this->signIn();
        $this->postJson('/api/admin/verifications/'.$item->id.'/'.$action, $this->payload($item, ['reason' => $reason]))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('for_verification', $item->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_revision_resubmission_rejects_stale_review_and_can_then_complete(): void
    {
        $donor = User::factory()->create();
        $item = $this->submission($donor);
        $admin = $this->signIn();
        $stale = $this->payload($item);
        $this->postJson('/api/admin/verifications/'.$item->id.'/needs-revision', [...$stale, 'reason' => 'Upload a clearer certificate.'])->assertOk()->assertJsonPath('verification.status', 'needs_revision');
        $this->postJson('/api/admin/verifications/'.$item->id.'/needs-revision', [...$stale, 'reason' => 'Duplicate'])->assertConflict();
        $this->assertDatabaseCount('point_transactions', 0);
        $this->signIn($donor);
        $this->getJson('/api/donation-participations/'.$item->id)->assertJsonPath('participation.revision_reason', 'Upload a clearer certificate.');
        $this->postJson('/api/donation-participations/'.$item->id.'/proof', ['proof' => UploadedFile::fake()->image('replacement.png')])->assertOk()
            ->assertJsonPath('participation.status', 'for_verification')->assertJsonPath('participation.revision_reason', null);
        $this->signIn($admin);
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $stale)->assertConflict();
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $this->payload($item->refresh()))->assertOk()->assertJsonPath('verification.status', 'completed');
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'verification_needs_revision', 'target_id' => $item->id]);
        $this->assertStringNotContainsString('donation-proofs/', AuditLog::all()->toJson());
        Queue::assertPushed(SendImportantPush::class, 2);
    }

    public function test_rejection_is_final_without_points_and_donor_sees_reason(): void
    {
        $donor = User::factory()->create();
        $item = $this->submission($donor);
        $this->signIn();
        $this->postJson('/api/admin/verifications/'.$item->id.'/reject', $this->payload($item, ['reason' => 'Invalid certificate.']))->assertOk()->assertJsonPath('verification.status', 'rejected');
        $this->postJson('/api/admin/verifications/'.$item->id.'/reject', $this->payload($item, ['reason' => 'Retry']))->assertConflict();
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'verification_rejected', 'target_id' => $item->id]);
        $this->signIn($donor);
        $this->getJson('/api/donation-participations/'.$item->id)->assertJsonPath('participation.rejection_reason', 'Invalid certificate.');
        $this->postJson('/api/donation-participations/'.$item->id.'/proof', ['proof' => UploadedFile::fake()->image('again.png')])->assertConflict();
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0)->assertJsonPath('achievement.key', 'new_donor');
        Queue::assertPushed(SendImportantPush::class, 1);
    }

    public static function invalidTransitions(): array
    {
        $cases = [];
        foreach (['pending', 'needs_revision', 'completed', 'rejected', 'cancelled'] as $status) {
            foreach (['approve', 'needs-revision', 'reject'] as $action) {
                $cases[$status.' '.$action] = [$status, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidTransitions')]
    public function test_non_reviewable_status_cannot_change(string $status, string $action): void
    {
        $item = $this->submission(status: $status);
        $this->signIn();
        $this->postJson('/api/admin/verifications/'.$item->id.'/'.$action, $this->payload($item, ['reason' => 'Reason']))->assertConflict();
        $this->assertSame($status, $item->refresh()->status);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_award_schedule_counts_completed_once_across_reviewers(): void
    {
        $donor = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $super = User::factory()->create(['role' => 'super_admin']);
        foreach ([300, 350, 400, 450, 500, 500] as $index => $amount) {
            $item = $this->submission($donor);
            $this->signIn($admin);
            $payload = $this->payload($item);
            $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $payload)->assertOk()->assertJsonPath('verification.completion.points_awarded', $amount);
            $this->signIn($super);
            $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $payload)->assertConflict();
            $this->assertDatabaseCount('point_transactions', $index + 1);
        }
        $this->assertSame([300, 350, 400, 450, 500, 500], PointTransaction::orderBy('id')->pluck('amount')->all());
        $this->signIn($donor);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 6)->assertJsonPath('achievement.key', 'silver_donor');
        $this->getJson('/api/points/summary')->assertJsonPath('current_balance', 2500);
        Queue::assertPushed(SendImportantPush::class, 6);
    }

    public function test_push_failure_cannot_roll_back_completed_award(): void
    {
        $item = $this->submission();
        $this->signIn();
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('Queue down'));
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', $this->payload($item))->assertOk();
        $this->assertDatabaseHas('donation_participations', ['id' => $item->id, 'status' => 'completed']);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
