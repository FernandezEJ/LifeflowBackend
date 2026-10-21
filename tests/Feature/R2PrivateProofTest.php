<?php

namespace Tests\Feature;

use App\Models\DonationParticipation;
use App\Models\User;
use App\Services\Admin\VerificationService;
use App\Services\DonationCooldown;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class R2PrivateProofTest extends TestCase
{
    private const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-21 04:00:00');
        Queue::fake();
        config(['filesystems.proof_disk' => 'r2_proofs', 'filesystems.disks.r2_proofs.key' => 'dummy-key',
            'filesystems.disks.r2_proofs.secret' => 'dummy-secret', 'filesystems.disks.r2_proofs.bucket' => 'lifeflow-proofs',
            'filesystems.disks.r2_proofs.endpoint' => 'https://example.r2.cloudflarestorage.com']);
        Storage::fake('proofs');
        Storage::fake('r2_proofs');
    }

    private function signIn(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('proof-test')->plainTextToken);
    }

    private function activity(User $owner): DonationParticipation
    {
        $item = new DonationParticipation;
        $item->forceFill(['user_id' => $owner->id, 'source_type' => 'red_cross_dagupan', 'joined_at' => now(), 'status' => 'pending'])->save();

        return $item;
    }

    private function upload(DonationParticipation $item): TestResponse
    {
        return $this->postJson('/api/donation-participations/'.$item->id.'/proof',
            ['proof' => UploadedFile::fake()->createWithContent('donor-original.pdf', self::PDF)]);
    }

    private function key(DonationParticipation $item): string
    {
        return str_starts_with($item->proof_path, 'r2_proofs:') ? substr($item->proof_path, 10) : $item->proof_path;
    }

    public static function proofDisks(): array
    {
        return ['legacy local' => ['proofs'], 'private R2' => ['r2_proofs']];
    }

    #[DataProvider('proofDisks')]
    public function test_owner_and_both_review_roles_read_proofs_after_write_switch_changes(string $disk): void
    {
        config(['filesystems.proof_disk' => $disk]);
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk()->assertJsonPath('participation.status', 'for_verification')
            ->assertJsonMissingPath('participation.proof_path')->assertJsonMissingPath('participation.proof_url');
        $item->refresh();
        Storage::disk($disk)->assertExists($this->key($item));
        $this->assertStringNotContainsString('donor-original', $item->proof_path);
        $this->assertSame([], Storage::disk($disk === 'proofs' ? 'r2_proofs' : 'proofs')->allFiles());
        config(['filesystems.proof_disk' => $disk === 'proofs' ? 'r2_proofs' : 'proofs']);

        $read = $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertStreamedContent(self::PDF);
        $this->assertStringContainsString('private', $read->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $read->headers->get('Cache-Control'));
        $this->assertStringStartsWith('attachment;', $read->headers->get('Content-Disposition'));
        foreach (['admin', 'super_admin'] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            $review = $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertOk()->assertStreamedContent(self::PDF);
            $this->assertStringStartsWith('inline;', $review->headers->get('Content-Disposition'));
        }
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_pending_replacement_and_duplicate_retry_retain_original_objects(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        $oldKey = $this->key($item->refresh());
        $item->forceFill(['status' => 'pending'])->save();

        $this->upload($item)->assertOk();
        $this->assertNotSame($oldKey, $this->key($item->refresh()));
        Storage::disk('r2_proofs')->assertExists($oldKey);
        $this->upload($item)->assertConflict();
        $this->assertCount(2, Storage::disk('r2_proofs')->allFiles());
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_revision_resubmission_preserves_old_proof_stale_review_points_and_cooldown_rules(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        $oldKey = $this->key($item->refresh());
        $staff = User::factory()->create(['role' => 'admin']);
        $this->signIn($staff);
        $oldVersion = app(VerificationService::class)->version($item);
        $this->postJson('/api/admin/verifications/'.$item->id.'/needs-revision', ['proof_version' => $oldVersion,
            'reason' => 'Please send a clearer copy.'])->assertOk();
        $this->signIn($owner);
        config(['filesystems.proof_disk' => 'proofs']);
        $this->upload($item)->assertOk()->assertJsonPath('participation.revision_reason', null);
        Storage::disk('r2_proofs')->assertExists($oldKey);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->signIn($staff);
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', ['proof_version' => $oldVersion])->assertConflict();
        $version = app(VerificationService::class)->version($item->refresh());
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', ['proof_version' => $version])->assertOk();
        $this->postJson('/api/admin/verifications/'.$item->id.'/approve', ['proof_version' => $version])->assertConflict();
        $this->assertDatabaseHas('point_transactions', ['user_id' => $owner->id, 'amount' => 300]);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertTrue(app(DonationCooldown::class)->metadata($owner)['is_on_donation_cooldown']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'verification_needs_revision', 'target_id' => $item->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'verification_approved', 'target_id' => $item->id]);
    }

    public static function closedStatuses(): array
    {
        return [['for_verification'], ['completed'], ['rejected'], ['cancelled']];
    }

    public static function decisions(): array
    {
        return ['approved' => ['approve', 'completed', 1], 'rejected' => ['reject', 'rejected', 0]];
    }

    #[DataProvider('decisions')]
    public function test_r2_proofs_preserve_review_outcomes_and_award_only_on_approval(string $action, string $status, int $awards): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        $version = app(VerificationService::class)->version($item->refresh());
        $this->signIn(User::factory()->create(['role' => 'super_admin']));

        $this->postJson('/api/admin/verifications/'.$item->id.'/'.$action,
            ['proof_version' => $version, ...($action === 'reject' ? ['reason' => 'This is not valid donation proof.'] : [])])
            ->assertOk()->assertJsonPath('verification.status', $status);

        $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertOk()->assertStreamedContent(self::PDF);
        $this->assertDatabaseCount('point_transactions', $awards);
        $this->assertSame($awards === 1, app(DonationCooldown::class)->metadata($owner)['is_on_donation_cooldown']);
        Storage::disk('r2_proofs')->assertExists($this->key($item));
    }

    public static function imageTypes(): array
    {
        return [['jpg', 'image/jpeg'], ['png', 'image/png']];
    }

    #[DataProvider('imageTypes')]
    public function test_valid_image_bytes_are_proxied_with_their_original_safe_content_type(string $extension, string $mime): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $image = UploadedFile::fake()->image('proof.'.$extension);
        $expected = hash_file('sha256', $image->getRealPath());
        $this->signIn($owner);

        $this->postJson('/api/donation-participations/'.$item->id.'/proof', ['proof' => $image])->assertOk();
        $read = $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertOk()->assertHeader('Content-Type', $mime);

        $this->assertSame($expected, hash('sha256', $read->streamedContent()));
    }

    public function test_exact_five_mib_proof_finishes_reading_before_the_success_response(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $bytes = str_pad(self::PDF, 5 * 1024 * 1024, ' ');
        $expected = hash('sha256', $bytes);
        $file = UploadedFile::fake()->createWithContent('max.pdf', $bytes);
        unset($bytes);
        $this->signIn($owner);

        $this->postJson('/api/donation-participations/'.$item->id.'/proof', ['proof' => $file])->assertOk();
        $read = $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertOk()->assertHeader('Content-Length', '5242880');

        $this->assertSame($expected, hash('sha256', $read->streamedContent()));
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_statuses_cannot_upload_to_r2(string $status): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $item->forceFill(['status' => $status])->save();
        $before = $item->refresh()->getAttributes();
        $this->signIn($owner);

        $this->upload($item)->assertConflict();
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('r2_proofs')->allFiles());
    }

    public function test_unrelated_donors_deactivated_accounts_and_revoked_tokens_cannot_read_or_upload(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        $url = '/api/donation-participations/'.$item->id.'/proof';
        $this->signIn(User::factory()->create());
        $this->getJson($url)->assertNotFound();
        $this->upload($item)->assertNotFound();
        $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertForbidden();
        $owner->forceFill(['deactivated_at' => now()])->save();
        $item->forceFill(['status' => 'needs_revision'])->save();
        $this->signIn($owner);
        $this->getJson($url)->assertForbidden();
        $this->upload($item)->assertForbidden();
        $owner->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        $this->upload($item)->assertUnauthorized();
        $this->assertCount(1, Storage::disk('r2_proofs')->allFiles());
    }

    public function test_reviewers_with_revoked_access_or_initial_password_cannot_read_proofs(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        foreach ([['deactivated_at' => now()], ['must_change_password' => true]] as $blocked) {
            $this->signIn(User::factory()->create(['role' => 'admin', ...$blocked]));
            $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertForbidden();
        }
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/admin/verifications/'.$item->id.'/proof')->assertUnauthorized();
        $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertUnauthorized();
    }

    public function test_invalid_types_empty_content_and_size_limit_preserve_pending_state(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $invalid = UploadedFile::fake()->createWithContent('proof.jpg', '<?php echo "not an image";');
        foreach ([new UploadedFile($invalid->getPathname(), 'proof.jpg', 'image/jpeg', null, true),
            UploadedFile::fake()->createWithContent('empty.pdf', ''),
            UploadedFile::fake()->create('oversize.pdf', 5121, 'application/pdf')] as $file) {
            $this->postJson('/api/donation-participations/'.$item->id.'/proof', ['proof' => $file])->assertUnprocessable();
        }
        $this->postJson('/api/donation-participations/'.$item->id.'/proof',
            ['proof' => UploadedFile::fake()->create('max.pdf', 5120, 'application/pdf')])->assertOk();
        $this->assertSame(5242880, $item->fresh()->proof_size);
        $this->assertCount(1, Storage::disk('r2_proofs')->allFiles());
    }

    public static function badReferences(): array
    {
        return ['traversal' => ['r2_proofs:{prefix}../secret.pdf'], 'other donor' => ['r2_proofs:donation-proofs/999/999/11111111-1111-4111-8111-111111111111.pdf'],
            'remote URL' => ['https://example.test/secret.pdf'], 'media disk' => ['r2_media:{prefix}11111111-1111-4111-8111-111111111111.pdf'],
            'missing R2 never falls back to local' => ['r2_proofs:{prefix}11111111-1111-4111-8111-111111111111.pdf']];
    }

    #[DataProvider('badReferences')]
    public function test_invalid_and_missing_proofs_never_resolve_another_object_or_disk(string $reference): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $prefix = 'donation-proofs/'.$owner->id.'/'.$item->id.'/';
        $item->forceFill(['proof_path' => str_replace('{prefix}', $prefix, $reference), 'proof_mime_type' => 'application/pdf'])->save();
        Storage::disk('proofs')->put($prefix.'11111111-1111-4111-8111-111111111111.pdf', 'unrelated local file');
        $this->signIn($owner);

        $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertNotFound();
    }

    #[DataProvider('proofDisks')]
    public function test_failed_database_write_cleans_only_new_r2_object_and_keeps_previous_proof(string $oldDisk): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        config(['filesystems.proof_disk' => $oldDisk]);
        $this->upload($item)->assertOk();
        $item->refresh()->forceFill(['status' => 'needs_revision', 'revision_reason' => 'Keep this reason.'])->save();
        $before = $item->getAttributes();
        $files = [Storage::disk('proofs')->allFiles(), Storage::disk('r2_proofs')->allFiles()];
        config(['filesystems.proof_disk' => 'r2_proofs']);
        DonationParticipation::saving(static function (DonationParticipation $record): void {
            if ($record->status === 'for_verification') {
                throw new RuntimeException('Simulated database failure');
            }
        });

        $this->upload($item)->assertStatus(500);
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame($files, [Storage::disk('proofs')->allFiles(), Storage::disk('r2_proofs')->allFiles()]);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_storage_failures_return_safe_503_and_never_delete_an_uncertain_write(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('secret-header private-object-key'));
        $disk->shouldReceive('delete')->never();
        Storage::partialMock()->shouldReceive('disk')->with('r2_proofs')->andReturn($disk);

        $response = $this->upload($item)->assertStatus(503)->assertJsonPath('message', 'Private proof storage is unavailable. Please try again.');
        $this->assertStringNotContainsString('secret-header', $response->getContent());
        $this->assertNull($item->fresh()->proof_path);
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_network_read_failure_is_503_before_success_headers_or_bytes(): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('readStream')->once()->andThrow(new RuntimeException('secret-header private-object-key'));
        Storage::partialMock()->shouldReceive('disk')->with('r2_proofs')->andReturn($disk);

        $response = $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertStatus(503);
        $this->assertStringNotContainsString('secret-header', $response->getContent());
        $this->assertSame('for_verification', $item->fresh()->status);
    }

    public static function unsafeConfig(): array
    {
        return ['unknown switch' => ['filesystems.proof_disk', 'public'],
            'wrong bucket' => ['filesystems.disks.r2_proofs.bucket', 'lifeflow-media'],
            'public visibility' => ['filesystems.disks.r2_proofs.visibility', 'public'],
            'public URL' => ['filesystems.disks.r2_proofs.url', 'https://public.example.test']];
    }

    #[DataProvider('unsafeConfig')]
    public function test_unsafe_configuration_blocks_new_writes_without_disabling_local_history(string $key, mixed $value): void
    {
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        config(['filesystems.proof_disk' => 'proofs']);
        $this->upload($item)->assertOk();
        $item->refresh()->forceFill(['status' => 'needs_revision'])->save();
        config(['filesystems.proof_disk' => 'r2_proofs', $key => $value]);

        $this->upload($item)->assertStatus(503);
        $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertOk()->assertStreamedContent(self::PDF);
        $this->assertSame([], Storage::disk('r2_proofs')->allFiles());
    }

    public function test_s3_adapter_sends_no_acl_or_public_link_and_proxies_the_actual_bytes(): void
    {
        $calls = [];
        config(['filesystems.disks.r2_proofs.handler' => function (CommandInterface $command, RequestInterface $request) use (&$calls): PromiseInterface {
            $calls[] = $command->getName();
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertNull($command['ACL']);
            if ($command->getName() === 'PutObject') {
                $this->assertSame('*', $command['IfNoneMatch']);
                $this->assertSame('application/pdf', $command['ContentType']);
            }

            return Create::promiseFor(new Result(['ContentLength' => strlen(self::PDF), 'Body' => Utils::streamFor(self::PDF)]));
        }]);
        Storage::purge('r2_proofs');
        $owner = User::factory()->create();
        $item = $this->activity($owner);
        $this->signIn($owner);
        $this->upload($item)->assertOk()->assertJsonMissingPath('participation.proof_path')->assertJsonMissingPath('participation.proof_url');

        $this->getJson('/api/donation-participations/'.$item->id.'/proof')->assertOk()->assertStreamedContent(self::PDF);
        $this->assertSame(['PutObject', 'HeadObject', 'GetObject'], $calls);
    }
}
