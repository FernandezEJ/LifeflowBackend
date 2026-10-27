<?php

namespace Tests\Feature;

use App\Models\EducationalArticle;
use App\Models\User;
use App\Services\EducationalArticleService;
use App\Services\MediaStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationalArticleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Storage::fake('public');
    }

    private function signIn(string $role = 'admin', array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, ...$extra]);
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('education-test')->plainTextToken);

        return $user;
    }

    private function data(array $extra = []): array
    {
        return [...['title' => 'Test learning article', 'category' => 'donor_tips', 'short_description' => 'Test preview',
            'description' => 'Test full content', 'description_document' => ['version' => 1, 'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Test full content', 'bold' => true]]],
                ['type' => 'bullet_list', 'items' => [[['text' => 'Test list item', 'italic' => true]]]],
            ]]], ...$extra];
    }

    private function complete(array $extra = []): EducationalArticle
    {
        $path = app(MediaStorage::class)->store(UploadedFile::fake()->image('test.png'), 'education');

        return EducationalArticle::factory()->create($this->data(['image_path' => $path, ...$extra]));
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('staffRoles')]
    public function test_both_staff_roles_manage_articles_and_restore_only_to_draft_without_push(string $role): void
    {
        Queue::fake();
        $actor = $this->signIn($role);
        $id = $this->postJson('/api/admin/educational-articles', [])->assertCreated()->assertJsonPath('article.status', 'draft')->json('article.id');
        $this->post('/api/admin/educational-articles/'.$id, $this->data(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('cover.png')]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('article.status', 'draft');
        $this->postJson('/api/admin/educational-articles/'.$id.'/publish')->assertOk()->assertJsonPath('article.status', 'published');
        $this->putJson('/api/admin/educational-articles/'.$id, ['title' => 'Live edited title'])->assertOk()->assertJsonPath('article.status', 'published');
        $this->putJson('/api/admin/educational-articles/'.$id, ['status' => 'draft'])->assertConflict();
        $this->deleteJson('/api/admin/educational-articles/'.$id)->assertOk()->assertJsonPath('article.status', 'deleted');
        $this->assertSoftDeleted('educational_articles', ['id' => $id]);
        $path = EducationalArticle::withTrashed()->findOrFail($id)->image_path;
        Storage::disk('public')->assertExists($path);
        $this->getJson('/api/admin/educational-articles/'.$id)->assertOk();
        $this->putJson('/api/admin/educational-articles/'.$id, ['title' => 'Illegal edit'])->assertNotFound();
        $this->postJson('/api/admin/educational-articles/'.$id.'/publish')->assertNotFound();
        $this->postJson('/api/admin/educational-articles/'.$id.'/restore')->assertOk()->assertJsonPath('article.status', 'draft')->assertJsonPath('article.published_at', null);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $actor->id, 'module' => 'education', 'action' => 'educational_article_restore']);
        $this->signIn('donor');
        $this->getJson('/api/educational-articles/'.$id)->assertNotFound();
        $this->getJson('/api/educational-articles')->assertJsonCount(0, 'data');
        $this->signIn($role);
        $this->postJson('/api/admin/educational-articles/'.$id.'/publish')->assertOk();
        $this->signIn('donor');
        $this->getJson('/api/educational-articles/'.$id)->assertOk()->assertJsonPath('article.title', 'Live edited title')->assertJsonMissingPath('article.image_path');
        $this->assertDatabaseCount('donation_opportunities', 0);
        $this->assertDatabaseCount('point_transactions', 0);
        Queue::assertNothingPushed();
    }

    public function test_donor_reads_are_published_only_and_all_mutations_require_active_staff(): void
    {
        $this->getJson('/api/educational-articles')->assertUnauthorized();
        $this->postJson('/api/admin/educational-articles', [])->assertUnauthorized();
        $draft = $this->complete();
        $deleted = $this->complete(['status' => 'published']);
        $deleted->delete();
        $live = $this->complete(['status' => 'published', 'published_at' => now()]);
        $this->signIn('donor');
        $this->getJson('/api/educational-articles?status=draft')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->id);
        foreach ([$draft, $deleted] as $item) {
            $this->getJson('/api/educational-articles/'.$item->id)->assertNotFound();
        }
        foreach (['get', 'post', 'put', 'delete'] as $method) {
            $url = '/api/admin/educational-articles'.(in_array($method, ['put', 'delete'], true) ? '/'.$live->id : '');
            $this->{$method.'Json'}($url)->assertForbidden();
        }
        $this->postJson('/api/admin/educational-articles/'.$draft->id.'/publish')->assertForbidden();
        $this->postJson('/api/admin/educational-articles/'.$deleted->id.'/restore')->assertForbidden();
        $this->signIn('admin', ['must_change_password' => true]);
        $this->getJson('/api/admin/educational-articles')->assertForbidden();
        $this->signIn('super_admin', ['deactivated_at' => now()]);
        $this->postJson('/api/admin/educational-articles', [])->assertForbidden();
        $this->signIn('donor', ['deactivated_at' => now()]);
        $this->getJson('/api/educational-articles')->assertForbidden();
    }

    public function test_partial_draft_is_valid_but_publishing_requires_complete_structured_content_and_cover(): void
    {
        $this->signIn();
        $blank = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => '']]]]];
        $id = $this->postJson('/api/admin/educational-articles', ['description_document' => $blank])->assertCreated()->json('article.id');
        $this->postJson('/api/admin/educational-articles/'.$id.'/publish')->assertUnprocessable()->assertJsonValidationErrors('description_document');
        $this->putJson('/api/admin/educational-articles/'.$id, $this->data(['status' => 'published']))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseHas('educational_articles', ['id' => $id, 'status' => 'draft', 'title' => '']);
        $this->post('/api/admin/educational-articles', $this->data(['status' => 'published', 'image' => UploadedFile::fake()->image('cover.jpg')]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('article.status', 'published');
    }

    public function test_invalid_input_and_unsafe_rich_text_cannot_be_saved_even_as_draft(): void
    {
        $this->signIn();
        foreach ([['category' => 'arbitrary'], ['title' => str_repeat('x', 256)], ['short_description' => str_repeat('x', 161)],
            ['description_document' => ['version' => 1, 'blocks' => [['type' => 'script', 'content' => []]]]],
            ['description_document' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Link', 'href' => 'javascript:alert(1)']]]]]],
            ['image_path' => 'proofs/private.pdf'], ['expires_at' => '2030-01-01']] as $invalid) {
            $this->postJson('/api/admin/educational-articles', $invalid)->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        }
        foreach ([UploadedFile::fake()->create('unsafe.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('huge.png')->size(5121)] as $file) {
            $this->post('/api/admin/educational-articles', ['image' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('educational_articles', 0);
    }

    public function test_failed_publish_cleans_new_cover_without_creating_an_article(): void
    {
        $this->signIn();
        $this->post('/api/admin/educational-articles', $this->data(['title' => '', 'status' => 'published', 'image' => UploadedFile::fake()->image('cover.png')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('educational_articles', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('education'));
    }

    public function test_counts_filters_search_and_pagination_are_independent_and_deterministic(): void
    {
        $this->signIn();
        EducationalArticle::factory()->count(22)->create(['title' => 'Matching article', 'category' => 'blood_basics', 'status' => 'published', 'published_at' => now()]);
        $draft = EducationalArticle::factory()->create();
        $deleted = EducationalArticle::factory()->create();
        $deleted->delete();
        $this->getJson('/api/admin/educational-articles?status=published&category=blood_basics&search=Matching')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 22)->assertJsonPath('counts', ['published' => 22, 'draft' => 1, 'deleted' => 1]);
        $this->getJson('/api/admin/educational-articles?status=published&page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/admin/educational-articles?status=draft')->assertJsonPath('data.0.id', $draft->id);
        $this->getJson('/api/admin/educational-articles?status=deleted')->assertJsonPath('data.0.id', $deleted->id);
        $this->signIn('donor');
        $this->getJson('/api/educational-articles')->assertJsonPath('data.0.id', 22)->assertJsonCount(20, 'data');
        $this->getJson('/api/educational-articles?page=2')->assertJsonCount(2, 'data');
    }

    public function test_failed_write_rolls_back_and_discards_only_the_new_image(): void
    {
        $actor = $this->signIn();
        $article = $this->complete();
        $old = $article->image_path;
        DB::statement('CREATE TRIGGER fail_education_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, "audit test failure"); END');
        try {
            app(EducationalArticleService::class)->save($actor, ['title' => 'Failed change', 'image' => UploadedFile::fake()->image('new.png')], $article->id);
            $this->fail('Expected transaction failure');
        } catch (QueryException) {
            $this->assertSame('Test learning article', $article->fresh()->title);
            $this->assertSame([$old], Storage::disk('public')->allFiles('education'));
        }
    }

    public function test_r2_cover_is_private_signed_and_retained_after_delete_and_restore(): void
    {
        config(['filesystems.media_disk' => 'r2_media', 'filesystems.disks.r2_media.key' => 'dummy', 'filesystems.disks.r2_media.secret' => 'dummy',
            'filesystems.disks.r2_media.endpoint' => 'https://example.r2.cloudflarestorage.com', 'filesystems.disks.r2_media.bucket' => 'lifeflow-media']);
        $disk = Storage::fake('r2_media');
        $disk->buildTemporaryUrlsUsing(fn () => 'https://signed.test/education.png');
        $this->signIn();
        $id = $this->post('/api/admin/educational-articles', $this->data(['status' => 'published', 'image' => UploadedFile::fake()->image('cover.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('article.image_url', 'https://signed.test/education.png')->assertJsonMissingPath('article.image_path')->json('article.id');
        $path = EducationalArticle::findOrFail($id)->image_path;
        $this->assertStringStartsWith('r2_media:education/', $path);
        $this->post('/api/admin/educational-articles/'.$id, ['_method' => 'PUT', 'title' => '', 'image' => UploadedFile::fake()->image('replacement.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame([substr($path, strlen('r2_media:'))], $disk->allFiles('education'));
        $this->assertSame('Test learning article', EducationalArticle::findOrFail($id)->title);
        $this->deleteJson('/api/admin/educational-articles/'.$id)->assertOk();
        $disk->assertExists(substr($path, strlen('r2_media:')));
        $this->postJson('/api/admin/educational-articles/'.$id.'/restore')->assertOk()->assertJsonPath('article.status', 'draft');
        $this->signIn('donor');
        $this->getJson('/api/educational-articles/'.$id)->assertNotFound();
        $this->assertNull(EducationalArticle::findOrFail($id)->image_url);
    }
}
