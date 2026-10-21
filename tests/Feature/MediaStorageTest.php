<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use App\Services\Admin\AnnouncementService;
use App\Services\Admin\RewardManagementService;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-21 04:00:00');
        Queue::fake();
        config(['filesystems.media_disk' => 'r2_media',
            'filesystems.disks.r2_media.key' => 'dummy-key', 'filesystems.disks.r2_media.secret' => 'dummy-secret',
            'filesystems.disks.r2_media.endpoint' => 'https://example.r2.cloudflarestorage.com',
            'filesystems.disks.r2_media.bucket' => 'lifeflow-media']);
        Storage::fake('public');
        Storage::fake('r2_media')->buildTemporaryUrlsUsing(fn ($path, $expiry): string => 'https://example.r2.cloudflarestorage.com/lifeflow-media/'.$path.'?expires='.$expiry->getTimestamp());
    }

    private function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create(['role' => 'admin']);
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('media-test')->plainTextToken);

        return $user;
    }

    private function data(string $collection, array $changes = []): array
    {
        return [...($collection === 'announcements'
            ? ['title' => 'Media drive', 'description' => 'Unchanged event content', 'location' => 'Dagupan',
                'donation_date' => '2026-11-01', 'expires_at' => '2026-11-01', 'status' => 'published', 'notify_donors' => false]
            : ['name' => 'Media reward', 'category' => 'merchandise', 'points_cost' => 100, 'stock_quantity' => 5, 'status' => 'active']),
            ...$changes];
    }

    private function rewardWithImage(): Reward
    {
        $this->signIn();
        $id = $this->post('/api/admin/rewards', $this->data('rewards', ['image' => UploadedFile::fake()->image('reward.png')]),
            ['Accept' => 'application/json'])->assertCreated()->json('reward.id');

        return Reward::findOrFail($id);
    }

    private function buy(Reward $reward, User $donor): UserVoucher
    {
        DB::table('point_transactions')->insert(['user_id' => $donor->id, 'type' => 'donation_reward', 'amount' => 500,
            'event_key' => 'media-test:'.Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($donor);
        $id = $this->postJson('/api/rewards/'.$reward->id.'/redeem', ['request_key' => (string) Str::uuid()])
            ->assertOk()->json('voucher.id');

        return UserVoucher::findOrFail($id);
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'super admin' => ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_staff_create_replace_and_donors_read_both_r2_image_types(string $role): void
    {
        $actor = $this->signIn(User::factory()->create(['role' => $role]));
        $proofConfig = config('filesystems.disks.proofs');
        $default = config('filesystems.default');
        foreach (['announcements', 'rewards'] as $collection) {
            $field = $collection === 'announcements' ? 'announcement' : 'reward';
            $model = $collection === 'announcements' ? DonationOpportunity::class : Reward::class;
            $created = $this->post('/api/admin/'.$collection, $this->data($collection, ['image' => UploadedFile::fake()->image('private-name.png')]),
                ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath($field.'.image_path');
            $id = $created->json($field.'.id');
            $oldReference = $model::findOrFail($id)->image_path;
            $this->assertMatchesRegularExpression('~^r2_media:'.$collection.'/[a-f0-9-]{36}\.png$~', $oldReference);
            $this->assertStringContainsString('/lifeflow-media/'.$collection.'/', $created->json($field.'.image_url'));
            $this->assertSame([], Storage::disk('public')->allFiles());
            $oldKey = substr($oldReference, strlen('r2_media:'));
            Storage::disk('r2_media')->assertExists($oldKey);

            $updated = $this->post('/api/admin/'.$collection.'/'.$id, $this->data($collection, ['_method' => 'PUT',
                'expected_stock' => 5, 'image' => UploadedFile::fake()->image('replacement.jpg')]), ['Accept' => 'application/json'])->assertOk();
            $newReference = $model::findOrFail($id)->image_path;
            $this->assertNotSame($oldReference, $newReference);
            Storage::disk('r2_media')->assertExists($oldKey);
            Storage::disk('r2_media')->assertExists(substr($newReference, strlen('r2_media:')));
            $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $actor->id,
                'action' => $collection === 'announcements' ? 'announcement_updated' : 'reward_updated']);

            $this->signIn(User::factory()->create());
            $this->getJson('/api/'.$collection.'/'.$id)->assertOk()
                ->assertJsonPath($field === 'announcement' ? 'opportunity.image_url' : 'reward.image_url', $updated->json($field.'.image_url'));
            $this->signIn($actor);
        }
        $this->assertSame($proofConfig, config('filesystems.disks.proofs'));
        $this->assertSame($default, config('filesystems.default'));
    }

    public function test_switch_rollback_only_changes_new_writes_and_preserves_legacy_and_r2_reads(): void
    {
        $reward = $this->rewardWithImage();
        $r2Reference = $reward->image_path;
        config(['filesystems.media_disk' => 'public']);
        $this->getJson('/api/admin/rewards/'.$reward->id)->assertOk()
            ->assertJsonPath('reward.image_url', Storage::disk('r2_media')->temporaryUrl(substr($r2Reference, 9), now()->addMinutes(15)));
        $this->post('/api/admin/rewards/'.$reward->id, $this->data('rewards', ['_method' => 'PUT', 'expected_stock' => 5,
            'image' => UploadedFile::fake()->image('local.png')]), ['Accept' => 'application/json'])->assertOk();
        $legacy = $reward->fresh();
        Storage::disk('public')->assertExists($legacy->image_path);
        Storage::disk('r2_media')->assertExists(substr($r2Reference, 9));
        config(['filesystems.media_disk' => 'r2_media']);

        $this->signIn(User::factory()->create());
        $this->getJson('/api/rewards/'.$legacy->id)->assertOk()->assertJsonPath('reward.image_url', url(Storage::disk('public')->url($legacy->image_path)));
    }

    public function test_owned_r2_snapshot_survives_replacement_deactivation_deletion_and_signed_url_expiry(): void
    {
        $reward = $this->rewardWithImage();
        $oldKey = substr($reward->image_path, 9);
        $donor = User::factory()->create();
        $voucher = $this->buy($reward, $donor);
        $snapshot = $voucher->reward_snapshot;
        $this->assertSame($reward->image_path, $snapshot['image_url']);
        $this->assertStringNotContainsString('X-Amz-', json_encode($snapshot));
        $first = $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->json('voucher.reward.image_url');
        $this->signIn();
        $this->post('/api/admin/rewards/'.$reward->id, $this->data('rewards', ['_method' => 'PUT', 'expected_stock' => 4,
            'stock_quantity' => 4, 'status' => 'inactive', 'image' => UploadedFile::fake()->image('new.png')]), ['Accept' => 'application/json'])->assertOk();
        $this->deleteJson('/api/admin/rewards/'.$reward->id)->assertOk();
        $this->travel(20)->minutes();

        $this->signIn($donor);
        $read = $this->getJson('/api/vouchers/'.$voucher->id)->assertOk();
        $this->assertStringContainsString('/'.$oldKey.'?', $read->json('voucher.reward.image_url'));
        $this->assertNotSame($first, $read->json('voucher.reward.image_url'));
        $this->assertSame($snapshot, $voucher->fresh()->reward_snapshot);
        Storage::disk('r2_media')->assertExists($oldKey);
        $code = $this->postJson('/api/vouchers/'.$voucher->id.'/activate')->assertOk()->json('voucher.claim_code');
        $this->signIn(User::factory()->create());
        $this->getJson('/api/vouchers/'.$voucher->id)->assertNotFound();
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertForbidden();
        $this->signIn();
        $claim = $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk();
        $this->assertStringContainsString('/'.$oldKey.'?', $claim->json('claim.reward.image_url'));
    }

    public function test_local_voucher_snapshot_and_bytes_remain_unchanged_after_cutover(): void
    {
        config(['filesystems.media_disk' => 'public']);
        $reward = $this->rewardWithImage();
        $localKey = $reward->image_path;
        $bytes = Storage::disk('public')->get($localKey);
        $donor = User::factory()->create();
        $voucher = $this->buy($reward, $donor);
        $snapshot = $voucher->reward_snapshot;
        config(['filesystems.media_disk' => 'r2_media']);
        $this->signIn();
        $this->post('/api/admin/rewards/'.$reward->id, $this->data('rewards', ['_method' => 'PUT', 'expected_stock' => 4,
            'stock_quantity' => 4, 'image' => UploadedFile::fake()->image('new.png')]), ['Accept' => 'application/json'])->assertOk();

        $this->signIn($donor);
        $this->getJson('/api/vouchers/'.$voucher->id)->assertOk()->assertJsonPath('voucher.reward.image_url', $snapshot['image_url']);
        $this->assertSame($snapshot, $voucher->fresh()->reward_snapshot);
        $this->assertSame($bytes, Storage::disk('public')->get($localKey));
    }

    public static function collections(): array
    {
        return ['announcement' => ['announcements'], 'reward' => ['rewards']];
    }

    #[DataProvider('collections')]
    public function test_database_failure_cleans_only_the_new_upload_and_keeps_original_reference(string $collection): void
    {
        $actor = $this->signIn();
        $model = $collection === 'announcements' ? DonationOpportunity::class : Reward::class;
        $service = app($collection === 'announcements' ? AnnouncementService::class : RewardManagementService::class);
        $item = $service->save($actor, $this->data($collection, ['image' => UploadedFile::fake()->image('old.png')]));
        $oldReference = $item->image_path;
        $before = Storage::disk('r2_media')->allFiles();
        $model::updating(static function (): void {
            throw new RuntimeException('Simulated database failure');
        });

        try {
            $service->save($actor, $this->data($collection, ['expected_stock' => 5, 'image' => UploadedFile::fake()->image('new.png')]), $item->id);
            $this->fail('Expected database failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated database failure', $error->getMessage());
        }

        $this->assertSame($oldReference, $item->fresh()->image_path);
        $this->assertSame($before, Storage::disk('r2_media')->allFiles());
        $this->assertDatabaseCount('audit_logs', $collection === 'announcements' ? 2 : 1);
    }

    #[DataProvider('collections')]
    public function test_upload_failure_returns_safe_503_without_database_reference(string $collection): void
    {
        $this->signIn();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Never print private-url Authorization dummy-secret'));
        Storage::partialMock()->shouldReceive('disk')->with('r2_media')->andReturn($disk);

        $response = $this->post('/api/admin/'.$collection, $this->data($collection, ['image' => UploadedFile::fake()->image('fail.png')]),
            ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('message', 'Unable to store the image. Please try again.');

        $this->assertStringNotContainsString('dummy-secret', $response->getContent());
        $this->assertDatabaseCount($collection === 'announcements' ? 'donation_opportunities' : 'rewards', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function hiddenAnnouncements(): array
    {
        return ['draft' => [['status' => 'draft']], 'future' => [['published_at' => '2026-10-22 04:00:00']],
            'deleted' => [['deleted_at' => '2026-10-20 04:00:00']]];
    }

    #[DataProvider('hiddenAnnouncements')]
    public function test_donor_history_cannot_supply_r2_urls_for_unpublished_or_deleted_announcements(array $changes): void
    {
        $key = 'announcements/'.Str::uuid().'.png';
        Storage::disk('r2_media')->put($key, 'image');
        $item = DonationOpportunity::create(['title' => 'Restricted', 'description' => 'Event', 'location' => 'Dagupan',
            'event_date' => '2026-11-01', 'status' => 'published', 'published_at' => now()->subDay(),
            'expires_at' => now()->addDay(), 'source_type' => 'admin_announcement', 'image_path' => 'r2_media:'.$key, ...$changes]);
        $donor = User::factory()->create();
        $participation = new DonationParticipation;
        $participation->forceFill(['user_id' => $donor->id, 'donation_opportunity_id' => $item->id,
            'status' => 'pending', 'joined_at' => now(), 'source_type' => 'admin_announcement'])->save();
        $this->signIn($donor);

        $this->getJson('/api/announcements/'.$item->id)->assertNotFound();
        $this->getJson('/api/donation-participations/'.$participation->id)->assertOk()->assertJsonPath('participation.opportunity.image_url', null);
        $this->signIn();
        $read = $this->getJson('/api/admin/announcements/'.$item->id)->assertOk();
        $this->assertStringContainsString('/'.$key.'?', $read->json('announcement.image_url'));
    }

    public static function invalidReferences(): array
    {
        return ['missing object' => ['r2_media:rewards/11111111-1111-4111-8111-111111111111.png'],
            'traversal' => ['r2_media:rewards/../secret.png'], 'other namespace' => ['r2_media:announcements/11111111-1111-4111-8111-111111111111.png']];
    }

    public function test_expired_published_event_image_remains_visible_in_owned_activity_history(): void
    {
        $key = 'announcements/'.Str::uuid().'.png';
        Storage::disk('r2_media')->put($key, 'image');
        $item = DonationOpportunity::create(['title' => 'Past published drive', 'description' => 'Event', 'location' => 'Dagupan',
            'event_date' => '2026-10-20', 'status' => 'published', 'published_at' => now()->subDays(2),
            'expires_at' => now()->subDay(), 'source_type' => 'admin_announcement', 'image_path' => 'r2_media:'.$key]);
        $donor = User::factory()->create();
        $participation = new DonationParticipation;
        $participation->forceFill(['user_id' => $donor->id, 'donation_opportunity_id' => $item->id,
            'status' => 'pending', 'joined_at' => now()->subDays(2), 'source_type' => 'admin_announcement'])->save();
        $this->signIn($donor);

        $this->getJson('/api/announcements/'.$item->id)->assertNotFound();
        $read = $this->getJson('/api/donation-participations/'.$participation->id)->assertOk();
        $this->assertStringContainsString('/'.$key.'?', $read->json('participation.opportunity.image_url'));
    }

    public function test_legacy_announcement_url_survives_cutover_and_replacement_retains_the_original(): void
    {
        config(['filesystems.media_disk' => 'public']);
        $this->signIn();
        $created = $this->post('/api/admin/announcements', $this->data('announcements', ['image' => UploadedFile::fake()->image('old.png')]),
            ['Accept' => 'application/json'])->assertCreated();
        $id = $created->json('announcement.id');
        $oldKey = DonationOpportunity::findOrFail($id)->image_path;
        $bytes = Storage::disk('public')->get($oldKey);
        config(['filesystems.media_disk' => 'r2_media']);
        $this->putJson('/api/admin/announcements/'.$id, $this->data('announcements'))->assertOk()
            ->assertJsonPath('announcement.image_url', $created->json('announcement.image_url'));

        $this->post('/api/admin/announcements/'.$id, $this->data('announcements', ['_method' => 'PUT', 'image' => UploadedFile::fake()->image('new.png')]),
            ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($bytes, Storage::disk('public')->get($oldKey));
        $this->assertStringStartsWith('r2_media:announcements/', DonationOpportunity::findOrFail($id)->image_path);
    }

    #[DataProvider('invalidReferences')]
    public function test_missing_or_invalid_r2_media_returns_null_without_local_fallback(string $reference): void
    {
        Storage::disk('public')->put('rewards/11111111-1111-4111-8111-111111111111.png', 'unrelated local content');
        $reward = Reward::factory()->create(['image_path' => $reference]);
        $this->signIn(User::factory()->create());

        $this->getJson('/api/rewards/'.$reward->id)->assertOk()->assertJsonPath('reward.image_url', null);
        $this->assertSame($reference, $reward->fresh()->image_path);
    }

    public function test_unknown_write_disk_fails_closed_without_changing_records(): void
    {
        $this->signIn();
        config(['filesystems.media_disk' => 'not-an-approved-media-disk']);

        $this->post('/api/admin/rewards', $this->data('rewards', ['image' => UploadedFile::fake()->image('file.png')]),
            ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('message', 'Media storage configuration is invalid.');
        $this->assertDatabaseCount('rewards', 0);
        $this->assertSame([], Storage::disk('r2_media')->allFiles());
    }

    public static function unsafeMediaConfigurations(): array
    {
        return ['public visibility' => ['visibility', 'public'], 'wrong bucket' => ['bucket', 'not-the-media-bucket'],
            'custom public URL' => ['temporary_url', 'https://public.example.test'], 'missing credentials' => ['secret', '']];
    }

    #[DataProvider('unsafeMediaConfigurations')]
    public function test_r2_upload_rejects_unsafe_configuration_before_writing(string $field, mixed $value): void
    {
        $this->signIn();
        config(['filesystems.disks.r2_media.'.$field => $value]);

        $this->post('/api/admin/announcements', $this->data('announcements', ['image' => UploadedFile::fake()->image('file.png')]),
            ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('message', 'Private media storage configuration is invalid.');

        $this->assertDatabaseCount('donation_opportunities', 0);
        $this->assertSame([], Storage::disk('r2_media')->allFiles());
    }

    public static function hiddenRewards(): array
    {
        return ['inactive' => [['active' => false]], 'scheduled' => [['starts_at' => '2026-10-22 04:00:00']],
            'ended' => [['ends_at' => '2026-10-20 04:00:00']], 'deleted' => [['deleted_at' => '2026-10-20 04:00:00']]];
    }

    #[DataProvider('hiddenRewards')]
    public function test_donors_cannot_obtain_private_catalog_media_for_hidden_rewards(array $changes): void
    {
        $key = 'rewards/'.Str::uuid().'.png';
        Storage::disk('r2_media')->put($key, 'image');
        $reward = Reward::factory()->create(['image_path' => 'r2_media:'.$key, ...$changes]);
        $this->signIn(User::factory()->create());

        $this->getJson('/api/rewards/'.$reward->id)->assertNotFound();
        $this->getJson('/api/rewards')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull($reward->image_url);
        $this->signIn();
        $read = $this->getJson('/api/admin/rewards/'.$reward->id)->assertOk();
        $this->assertStringContainsString('/'.$key.'?', $read->json('reward.image_url'));
    }

    public function test_anonymous_requests_cannot_obtain_r2_image_urls(): void
    {
        $key = 'rewards/'.Str::uuid().'.png';
        Storage::disk('r2_media')->put($key, 'image');
        $reward = Reward::factory()->create(['image_path' => 'r2_media:'.$key]);

        $this->getJson('/api/rewards/'.$reward->id)->assertUnauthorized();
        $this->getJson('/api/admin/rewards/'.$reward->id)->assertUnauthorized();
        $this->assertNull($reward->image_url);
    }

    public function test_real_s3_adapter_omits_acls_uses_conditional_put_and_signs_only_get_requests(): void
    {
        $this->travelTo(new \DateTimeImmutable);
        $calls = [];
        config(['filesystems.disks.r2_media.handler' => function (CommandInterface $command, RequestInterface $request) use (&$calls): PromiseInterface {
            $calls[] = $command->getName();
            $this->assertFalse($request->hasHeader('x-amz-acl'));
            $this->assertNull($command['ACL']);
            if ($command->getName() === 'PutObject') {
                $this->assertSame('*', $command['IfNoneMatch']);
                $this->assertSame('*', $request->getHeaderLine('If-None-Match'));
                $this->assertSame('image/png', $command['ContentType']);
            }

            return Create::promiseFor(new Result(['ContentLength' => 1]));
        }]);
        Storage::purge('r2_media');
        $this->signIn();

        $response = $this->post('/api/admin/rewards', $this->data('rewards', ['image' => UploadedFile::fake()->image('private.png')]),
            ['Accept' => 'application/json'])->assertCreated();
        $url = $response->json('reward.image_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(['PutObject', 'HeadObject'], $calls);
        $this->assertSame('example.r2.cloudflarestorage.com', parse_url($url, PHP_URL_HOST));
        $this->assertGreaterThan(0, (int) $query['X-Amz-Expires']);
        $this->assertLessThanOrEqual(900, (int) $query['X-Amz-Expires']);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), strtotime($query['X-Amz-Date']) + (int) $query['X-Amz-Expires']);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
        $this->assertStringNotContainsString('X-Amz-', Reward::first()->getRawOriginal('image_path'));
        $this->assertNull(Reward::first()->getRawOriginal('image_url'));
    }
}
