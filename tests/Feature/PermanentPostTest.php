<?php

namespace Tests\Feature;

use App\Models\PermanentPost;
use App\Models\User;
use App\Services\Admin\PermanentPostService;
use App\Services\MediaStorage;
use Database\Seeders\PermanentPostSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermanentPostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    private function signIn(string $role = 'admin', array $attributes = []): User
    {
        $actor = User::factory()->create(['role' => $role, ...$attributes]);
        $this->withToken($actor->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $actor;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['title' => 'Updated chapter information', 'summary' => 'Updated short preview',
            'description' => 'Confirm arrangements with the chapter.'], $changes);
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_edit_is_persisted_on_board_and_detail_without_events_or_push(string $role): void
    {
        Queue::fake();
        $actor = $this->signIn($role);
        $this->getJson('/api/admin/permanent-post')->assertOk()->assertJsonMissingPath('post.expires_at');
        $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Saved rich details', 'bold' => true]]]]];
        $this->putJson('/api/admin/permanent-post', $this->payload(['description_document' => $document]))->assertOk()
            ->assertJsonPath('post.description', 'Saved rich details')->assertJsonPath('post.summary', 'Updated short preview');
        $this->assertDatabaseHas('permanent_posts', ['slug' => PermanentPost::RED_CROSS, 'description' => 'Saved rich details']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $actor->id, 'action' => 'permanent_post_updated']);
        $this->assertDatabaseCount('donation_opportunities', 0);
        $this->assertDatabaseCount('donation_participations', 0);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->signIn('donor');
        $this->getJson('/api/announcements')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('permanent_post.title', 'Updated chapter information');
        $this->travel(5)->years();
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertOk()
            ->assertJsonPath('opportunity.description_document', $document)->assertJsonPath('opportunity.event_date', null)
            ->assertJsonPath('opportunity.expires_at', null)->assertJsonMissingPath('opportunity.image_path');
        $this->seed(PermanentPostSeeder::class);
        $this->seed(PermanentPostSeeder::class);
        $this->assertDatabaseCount('permanent_posts', 1);
        $this->assertDatabaseHas('permanent_posts', ['title' => 'Updated chapter information']);
        Queue::assertNothingPushed();
    }

    public function test_authentication_and_staff_account_gates_protect_edits(): void
    {
        $this->getJson('/api/admin/permanent-post')->assertUnauthorized();
        $this->putJson('/api/admin/permanent-post', $this->payload())->assertUnauthorized();
        foreach ([['donor', []], ['admin', ['deactivated_at' => now()]], ['super_admin', ['must_change_password' => true]]] as [$role, $attributes]) {
            $this->signIn($role, $attributes);
            $this->getJson('/api/admin/permanent-post')->assertForbidden();
            $this->putJson('/api/admin/permanent-post', $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_validation_rejects_unsafe_documents_lifecycle_fields_and_invalid_images(): void
    {
        $this->signIn();
        $this->putJson('/api/admin/permanent-post', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'summary', 'description']);
        $this->putJson('/api/admin/permanent-post', $this->payload(['title' => str_repeat('x', 256), 'summary' => str_repeat('x', 501), 'description' => str_repeat('x', 10001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'summary', 'description']);
        $this->putJson('/api/admin/permanent-post', $this->payload(['description_document' => ['version' => 1, 'blocks' => [['type' => 'script', 'content' => []]]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('description_document');
        $this->putJson('/api/admin/permanent-post', $this->payload(['status' => 'draft', 'expires_at' => '2030-01-01', 'image_path' => 'proofs/private.png', 'slug' => 'another-post']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'expires_at', 'image_path', 'slug']);
        $this->post('/api/admin/permanent-post', $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('permanent_posts', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_local_image_replacement_keeps_old_media_and_exposes_no_storage_path(): void
    {
        Storage::fake('public');
        $this->signIn();
        $this->post('/api/admin/permanent-post', $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('cover.png')]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonMissingPath('post.image_path');
        $old = PermanentPost::redCross()->image_path;
        Storage::disk('public')->assertExists($old);
        $this->post('/api/admin/permanent-post', $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('replacement.jpg')]), ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists($old);
        Storage::disk('public')->assertExists(PermanentPost::redCross()->image_path);
        $this->assertNotSame($old, PermanentPost::redCross()->image_path);
        $this->signIn('donor');
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertOk()
            ->assertJsonPath('opportunity.image_url', Storage::disk('public')->url(PermanentPost::redCross()->image_path));
    }

    public function test_database_failure_discards_only_new_upload_and_preserves_saved_post(): void
    {
        Storage::fake('public');
        $actor = $this->signIn();
        Storage::disk('public')->put('announcements/old.png', 'old');
        PermanentPost::redCross()->update(['image_path' => 'announcements/old.png']);
        DB::statement('CREATE TRIGGER fail_permanent_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, "test audit failure"); END');
        try {
            app(PermanentPostService::class)->save($actor, $this->payload(['image' => UploadedFile::fake()->image('new.png')]));
            $this->fail('Expected a transaction failure');
        } catch (QueryException) {
            $this->assertDatabaseHas('permanent_posts', ['image_path' => 'announcements/old.png']);
            $this->assertSame(['announcements/old.png'], Storage::disk('public')->allFiles('announcements'));
        }
    }

    public function test_private_r2_media_is_signed_for_authorized_readers_and_hidden_otherwise(): void
    {
        config(['filesystems.disks.r2_media' => ['driver' => 's3', 'key' => 'test', 'secret' => 'test', 'region' => 'auto',
            'bucket' => 'lifeflow-media', 'endpoint' => 'https://test.r2.cloudflarestorage.com', 'visibility' => 'private',
            'directory_visibility' => 'private', 'use_path_style_endpoint' => true, 'serve' => false]]);
        $disk = Storage::fake('r2_media');
        $key = 'announcements/11111111-1111-1111-1111-111111111111.png';
        $disk->put($key, 'image');
        $disk->buildTemporaryUrlsUsing(fn () => 'https://signed.test/cover.png?temporary=1');
        PermanentPost::redCross()->update(['image_path' => 'r2_media:'.$key]);
        $this->signIn('donor');
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertOk()
            ->assertJsonPath('opportunity.image_url', 'https://signed.test/cover.png?temporary=1')->assertJsonMissingPath('opportunity.image_path');
        config(['filesystems.media_disk' => 'r2_media']);
        $this->signIn();
        $uploaded = $this->post('/api/admin/permanent-post', $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('private.png')]), ['Accept' => 'application/json'])->assertOk();
        $newReference = PermanentPost::redCross()->image_path;
        $this->assertStringStartsWith('r2_media:announcements/', $newReference);
        $disk->assertExists(substr($newReference, strlen('r2_media:')));
        $disk->assertExists($key);
        $uploaded->assertJsonPath('post.image_url', 'https://signed.test/cover.png?temporary=1')->assertJsonMissingPath('post.image_path');
        auth()->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/donation-opportunities/red-cross-dagupan')->assertUnauthorized();
        $this->assertNull(app(MediaStorage::class)->permanentPostUrl(PermanentPost::redCross()));
    }
}
