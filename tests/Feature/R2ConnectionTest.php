<?php

namespace Tests\Feature;

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class R2ConnectionTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $objects = [];

    /** @var array<string, list<array{name: string, key: string}>> */
    private array $requests = [];

    private const string PRIVATE_ERROR = 'test-secret Authorization https://private.example.test/never-print';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['r2_media', 'r2_proofs'] as $name) {
            config(['filesystems.disks.'.$name.'.key' => 'dummy-key',
                'filesystems.disks.'.$name.'.secret' => 'dummy-secret',
                'filesystems.disks.'.$name.'.endpoint' => 'https://example.r2.cloudflarestorage.com',
                'filesystems.disks.'.$name.'.bucket' => str_replace('_', '-', $name).'-test']);
            $this->fakeDisk($name);
        }
    }

    public function test_does_not_access_storage_without_explicit_opt_in(): void
    {
        Storage::shouldReceive('disk')->never();

        $this->artisan('r2:test-connection')
            ->expectsOutputToContain('Not tested.')
            ->assertFailed();
    }

    public function test_both_private_disks_write_verify_and_delete_only_their_own_unique_markers(): void
    {
        $this->artisan('r2:test-connection --confirm')
            ->expectsOutput('r2_media: PASS (write/read/delete).')
            ->expectsOutput('r2_proofs: PASS (write/read/delete).')
            ->assertSuccessful();

        foreach (['r2_media', 'r2_proofs'] as $name) {
            $this->assertSame('private', config('filesystems.disks.'.$name.'.visibility'));
            $this->assertSame('private', config('filesystems.disks.'.$name.'.directory_visibility'));
            $this->assertSame(['existing.txt' => 'preserve'], $this->objects[$name]);
            $this->assertSame(['PutObject', 'GetObject', 'DeleteObject'], array_column($this->requests[$name], 'name'));
            $this->assertCount(1, array_unique(array_column($this->requests[$name], 'key')));
        }
        $this->assertNotSame($this->requests['r2_media'][0]['key'], $this->requests['r2_proofs'][0]['key']);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_rejects_unsafe_or_incomplete_config_without_touching_that_disk(string $field, mixed $value): void
    {
        config(['filesystems.disks.r2_media.'.$field => $value]);

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutputToContain('r2_media: FAIL (configuration;')
            ->expectsOutput('r2_proofs: PASS (write/read/delete).')
            ->assertFailed();

        $this->assertSame([], $this->requests['r2_media']);
        $this->assertSame(['existing.txt' => 'preserve'], $this->objects['r2_media']);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'missing key' => ['key', ''],
            'missing secret' => ['secret', null],
            'missing bucket' => ['bucket', ''],
            'missing endpoint' => ['endpoint', null],
            'public visibility' => ['visibility', 'public'],
            'public directories' => ['directory_visibility', 'public'],
            'wrong driver' => ['driver', 'local'],
            'wrong region' => ['region', 'us-east-1'],
            'virtual host addressing' => ['use_path_style_endpoint', false],
            'local serving' => ['serve', true],
            'public URL' => ['url', 'https://public.example.test'],
            'unencrypted endpoint' => ['endpoint', 'http://example.r2.cloudflarestorage.com'],
            'non-R2 endpoint' => ['endpoint', 'https://example.test'],
            'embedded user' => ['endpoint', 'https://user@example.r2.cloudflarestorage.com'],
            'query parameters' => ['endpoint', 'https://example.r2.cloudflarestorage.com?secret=dummy'],
            'endpoint path' => ['endpoint', 'https://example.r2.cloudflarestorage.com/path'],
        ];
    }

    public function test_rejects_buckets_that_are_not_distinct(): void
    {
        config(['filesystems.disks.r2_proofs.bucket' => config('filesystems.disks.r2_media.bucket')]);

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutputToContain('r2_media: FAIL (configuration;')
            ->expectsOutputToContain('r2_proofs: FAIL (configuration;')
            ->assertFailed();

        $this->assertSame(['r2_media' => [], 'r2_proofs' => []], $this->requests);
    }

    #[DataProvider('failedOperations')]
    public function test_reports_failure_safely_and_still_tests_the_other_bucket(string $failure, string $cleanup, array $operations): void
    {
        $this->fakeDisk('r2_media', $failure);

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutput('r2_media: FAIL ('.$failure.'; '.$cleanup.').')
            ->doesntExpectOutputToContain(self::PRIVATE_ERROR)
            ->expectsOutput('r2_proofs: PASS (write/read/delete).')
            ->assertFailed();

        $this->assertSame($operations, array_column($this->requests['r2_media'], 'name'));
        $this->assertSame('preserve', $this->objects['r2_media']['existing.txt']);
        $this->assertSame(['existing.txt' => 'preserve'], $this->objects['r2_proofs']);
    }

    public static function failedOperations(): array
    {
        return [
            'write failed' => ['write', 'cleanup unverified', ['PutObject', 'GetObject']],
            'read failed' => ['read', 'own marker cleaned', ['PutObject', 'GetObject', 'DeleteObject']],
            'delete failed' => ['cleanup', 'cleanup unverified', ['PutObject', 'GetObject', 'DeleteObject']],
        ];
    }

    public function test_read_mismatch_is_not_reported_as_a_pass_and_own_marker_is_cleaned(): void
    {
        $this->fakeDisk('r2_media', 'mismatch');

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutput('r2_media: FAIL (read; own marker cleaned).')
            ->assertFailed();

        $this->assertSame(['existing.txt' => 'preserve'], $this->objects['r2_media']);
    }

    public function test_uncertain_write_cleans_up_only_after_verifying_ownership(): void
    {
        $this->fakeDisk('r2_media', 'uncertain');

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutput('r2_media: FAIL (write; own marker cleaned).')
            ->assertFailed();

        $this->assertSame(['PutObject', 'GetObject', 'DeleteObject'], array_column($this->requests['r2_media'], 'name'));
        $this->assertSame(['existing.txt' => 'preserve'], $this->objects['r2_media']);
    }

    public function test_marker_collision_does_not_overwrite_or_delete_the_existing_object(): void
    {
        $this->fakeDisk('r2_media', 'collision');

        $this->artisan('r2:test-connection --confirm')
            ->expectsOutput('r2_media: FAIL (write; cleanup unverified).')
            ->assertFailed();

        $this->assertSame(['PutObject', 'GetObject'], array_column($this->requests['r2_media'], 'name'));
        $this->assertSame('unrelated existing object', $this->objects['r2_media'][$this->requests['r2_media'][0]['key']]);
        $this->assertSame('preserve', $this->objects['r2_media']['existing.txt']);
    }

    private function fakeDisk(string $name, ?string $failure = null): void
    {
        $this->objects[$name] = ['existing.txt' => 'preserve'];
        $this->requests[$name] = [];
        config(['filesystems.disks.'.$name.'.handler' => function (CommandInterface $command) use ($name, $failure): PromiseInterface {
            $operation = $command->getName();
            $key = $command['Key'];
            $this->requests[$name][] = ['name' => $operation, 'key' => $key];
            $this->assertSame(config('filesystems.disks.'.$name.'.bucket'), $command['Bucket']);
            $this->assertMatchesRegularExpression('~^__lifeflow_connection_test__/[a-f0-9-]{36}\.txt$~', $key);
            $this->assertNull($command['ACL']);

            if ($operation === 'PutObject') {
                $this->assertSame('*', $command['IfNoneMatch']);
                if ($failure === 'collision') {
                    $this->objects[$name][$key] = 'unrelated existing object';

                    return Create::rejectionFor(new RuntimeException(self::PRIVATE_ERROR));
                }
                if ($failure === 'write') {
                    return Create::rejectionFor(new RuntimeException(self::PRIVATE_ERROR));
                }
                $this->objects[$name][$key] = (string) $command['Body'];
                if ($failure === 'uncertain') {
                    return Create::rejectionFor(new RuntimeException(self::PRIVATE_ERROR));
                }
            } elseif ($operation === 'GetObject') {
                if ($failure === 'read' || ! isset($this->objects[$name][$key])) {
                    return Create::rejectionFor(new RuntimeException(self::PRIVATE_ERROR));
                }

                return Create::promiseFor(new Result(['Body' => $failure === 'mismatch' ? 'wrong marker' : $this->objects[$name][$key]]));
            } elseif ($operation === 'DeleteObject') {
                if ($failure === 'cleanup') {
                    return Create::rejectionFor(new RuntimeException(self::PRIVATE_ERROR));
                }
                unset($this->objects[$name][$key]);
            } else {
                $this->fail('Unexpected storage operation.');
            }

            return Create::promiseFor(new Result);
        }]);
        Storage::purge($name);
    }
}
