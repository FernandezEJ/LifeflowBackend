<?php

namespace Tests\Feature;

use App\Models\DonorProfile;
use App\Models\Reward;
use App\Models\User;
use App\Services\EligibilityCooldown;
use App\Services\MediaStorage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class SecurityHardeningTest extends TestCase
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
        Storage::fake('public');
        Storage::fake('proofs');
    }

    public static function blockedSeeding(): array
    {
        $cases = [];
        foreach (['production', 'staging', '', 'LOCAL'] as $environment) {
            foreach (['default', 'staff', 'factory'] as $entry) {
                $cases[$environment.' '.$entry] = [$environment, $entry];
            }
        }

        return $cases;
    }

    #[DataProvider('blockedSeeding')]
    public function test_account_seeders_and_factory_fail_closed_before_any_write(string $environment, string $entry): void
    {
        $existing = User::factory()->create(['role' => 'super_admin'])->fresh();
        $before = $existing->getRawOriginal();
        $permanentPosts = DB::table('permanent_posts')->orderBy('id')->get()->toJson();
        config(['admin.super_admin.name' => 'Synthetic Staff', 'admin.super_admin.email' => $existing->email,
            'admin.super_admin.password' => 'Synthetic-Only-Password-123!']);
        $this->app->instance('env', $environment);
        try {
            try {
                match ($entry) {
                    // Direct invocation bypasses Artisan's confirmation; the guard must still refuse all writes.
                    'default' => app(DatabaseSeeder::class)->run(),
                    'staff' => app(SuperAdminSeeder::class)->run(),
                    'factory' => User::factory()->create(['role' => 'super_admin']),
                };
                $this->fail('Non-development account seeding must fail closed.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('restricted to local and testing', $error->getMessage());
            }
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame($permanentPosts, DB::table('permanent_posts')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 0);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function developmentEnvironments(): array
    {
        return [['local'], ['testing']];
    }

    #[DataProvider('developmentEnvironments')]
    public function test_explicit_local_and_test_seeding_still_work(string $environment): void
    {
        $this->app->instance('env', $environment);
        try {
            config(['admin.super_admin.name' => 'Synthetic Staff', 'admin.super_admin.email' => 'synthetic@example.test',
                'admin.super_admin.password' => 'Synthetic-Only-Password-123!']);
            $this->seed(DatabaseSeeder::class);
            $this->seed(SuperAdminSeeder::class);
            $this->seed(SuperAdminSeeder::class);
            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseCount('permanent_posts', 1);
            $staff = User::where('role', 'super_admin')->firstOrFail();
            $this->assertTrue($staff->must_change_password);
            $this->assertSame('Synthetic Staff', $staff->name);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function privateFailures(): array
    {
        return [['profile', false], ['profile', true], ['assessment', false], ['assessment', true]];
    }

    #[DataProvider('privateFailures')]
    public function test_private_write_failure_logs_only_operation_and_exception_class(string $operation, bool $sql): void
    {
        $user = User::factory()->create();
        $user->donorProfile()->create(['first_name' => 'Synthetic', 'last_name' => 'Donor', 'mobile_number' => '+639171234567',
            'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);
        $before = $user->refresh()->getRawOriginal();
        $token = $user->createToken('synthetic')->plainTextToken;
        $canary = 'PRIVATE-ANSWER-RECIPIENT-CREDENTIAL-CANARY';
        $error = $sql ? new QueryException('sqlite', 'update private_data set secret = ?', [$canary], new PDOException($canary))
            : new RuntimeException($canary, previous: new RuntimeException($canary));
        Exceptions::fake();
        Log::spy();
        try {
            if ($operation === 'profile') {
                DonorProfile::updating(static function () use ($error): void {
                    throw $error;
                });
                $response = $this->withToken($token)->putJson('/api/donor-profile', ['first_name' => 'Changed']);
                $response->assertStatus(500)->assertExactJson(['message' => 'Profile could not be updated. Please try again.']);
                $this->assertSame($before, $user->fresh()->getRawOriginal());
                $this->assertSame('Synthetic', $user->donorProfile()->firstOrFail()->first_name);
            } else {
                $this->mock(EligibilityCooldown::class)->shouldReceive('submit')->once()->andThrow($error);
                $response = $this->withToken($token)->postJson('/api/eligibility-assessments', ['answers' => EligibilityEvaluatorTest::answers()]);
                $response->assertStatus(500)->assertExactJson(['message' => 'Pre-screening could not be saved. Please try again.']);
                $this->assertDatabaseCount('eligibility_assessments', 0);
            }
            Log::shouldHaveReceived('error')->once()->with($operation === 'profile' ? 'Donor profile update failed.' : 'Eligibility assessment save failed.',
                ['exception_class' => $error::class]);
            Exceptions::assertNothingReported();
            $this->assertStringNotContainsString($canary, $response->getContent());
        } finally {
            DonorProfile::flushEventListeners();
        }
    }

    private function media(): array
    {
        config(['filesystems.disks.r2_media.key' => 'synthetic-key', 'filesystems.disks.r2_media.secret' => 'synthetic-secret',
            'filesystems.disks.r2_media.endpoint' => 'https://example.r2.cloudflarestorage.com', 'filesystems.disks.r2_media.bucket' => 'lifeflow-media']);
        auth()->setUser(User::factory()->create(['role' => 'admin']));
        $this->app->instance('request', Request::create('/synthetic-media-read'));
        $disk = Mockery::mock(FilesystemAdapter::class);
        Storage::shouldReceive('disk')->with('r2_media')->andReturn($disk);
        $reward = new Reward;
        $reward->forceFill(['image_path' => 'r2_media:rewards/11111111-1111-4111-8111-111111111111.png', 'active' => true]);

        return [$disk, $reward, app(MediaStorage::class)];
    }

    public function test_media_deduplicates_shared_references_without_cross_request_or_expired_url_reuse(): void
    {
        [$disk, $reward, $media] = $this->media();
        $disk->shouldReceive('exists')->times(3)->andReturn(true);
        $disk->shouldReceive('temporaryUrl')->times(3)->andReturnUsing(fn ($key, $expiry, $options): string => 'https://media.example.test/'.$key.'?expires='.$expiry->getTimestamp());
        $first = $media->rewardUrl($reward, null);
        foreach (range(1, 10) as $unused) {
            $this->assertSame($first, app(MediaStorage::class)->rewardUrl($reward, null));
            $this->assertSame($first, $media->snapshotUrl($reward->image_path));
        }
        $this->assertCount(1, request()->attributes->get('_lifeflow_media_urls'));
        $this->app->instance('request', Request::create('/another-authorized-read'));
        $this->assertSame($first, $media->rewardUrl($reward, null));
        $this->travel(16)->minutes();
        $this->assertNotSame($first, $media->rewardUrl($reward, null));
    }

    public function test_media_cache_never_bypasses_visibility_or_private_configuration(): void
    {
        [$disk, $reward, $media] = $this->media();
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://media.example.test/synthetic');
        $this->assertNotNull($media->rewardUrl($reward, null));
        $staff = auth()->user();
        auth()->setUser(User::factory()->create());
        $reward->active = false;
        $this->assertNull($media->rewardUrl($reward, null));
        auth()->setUser($staff);
        $staff->forceFill(['deactivated_at' => now()]);
        $this->assertNull($media->rewardUrl($reward, null));
        $staff->forceFill(['deactivated_at' => null]);
        config(['filesystems.disks.r2_media.visibility' => 'public']);
        $this->assertNull($media->rewardUrl($reward, null));
    }

    public static function missingMedia(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('missingMedia')]
    public function test_missing_or_failed_media_is_deduplicated_only_until_next_request(bool $throws): void
    {
        [$disk, $reward, $media] = $this->media();
        $expectation = $disk->shouldReceive('exists')->twice();
        $throws ? $expectation->andThrow(new RuntimeException('PRIVATE-SDK-CANARY')) : $expectation->andReturn(false);
        $disk->shouldNotReceive('temporaryUrl');
        foreach (range(1, 10) as $unused) {
            $this->assertNull($media->rewardUrl($reward, null));
        }
        $this->assertCount(1, request()->attributes->get('_lifeflow_media_urls'));
        $this->app->instance('request', Request::create('/retry-in-new-request'));
        $this->assertNull($media->rewardUrl($reward, null));
    }

    public function test_media_memo_has_a_fixed_capacity_and_rejects_bad_references(): void
    {
        [$disk, $reward, $media] = $this->media();
        $disk->shouldReceive('exists')->times(258)->andReturn(true);
        $disk->shouldReceive('temporaryUrl')->times(258)->andReturn('https://media.example.test/synthetic');
        foreach ([[null, null], ['', ''], ['r2_media:../private.pdf', null], ['r2_media:rewards/not-a-uuid.png', null]] as [$reference, $expected]) {
            $this->assertSame($expected, $media->snapshotUrl($reference));
        }
        foreach (range(1, 257) as $id) {
            $reward->image_path = 'r2_media:rewards/'.str_pad((string) $id, 8, '0', STR_PAD_LEFT).'-1111-4111-8111-111111111111.png';
            $this->assertNotNull($media->rewardUrl($reward, null));
        }
        $this->assertCount(256, request()->attributes->get('_lifeflow_media_urls'));
        $this->assertNotNull($media->rewardUrl($reward, null));
        $this->assertCount(256, request()->attributes->get('_lifeflow_media_urls'));
    }
}
