<?php

namespace Tests\Feature;

use App\Services\ProductionReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // This class does not migrate or query any database, even after changing the environment label/config.
        DB::shouldReceive('connection')->never();
        Cache::shouldReceive('store')->never();
        Storage::shouldReceive('disk')->never();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->app->instance('env', 'production');
        config(['app.debug' => false, 'app.url' => 'https://api.example.test', 'app.key' => 'base64:'.base64_encode(str_repeat('A', 32)),
            'app.previous_keys' => [], 'app.cipher' => 'AES-256-CBC', 'app.calendar_timezone' => 'Asia/Manila',
            'database.default' => 'mysql', 'database.connections.mysql.host' => 'synthetic-db',
            'database.connections.mysql.database' => 'synthetic', 'database.connections.mysql.username' => 'synthetic',
            'database.connections.mysql.url' => null, 'database.connections.mysql.password' => 'R6-PRIVATE-CANARY',
            'queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 90, 'cache.default' => 'database', 'cache.stores.database.driver' => 'database',
            'cors.allowed_origins' => ['https://admin.example.test'], 'cors.allowed_origins_patterns' => [], 'cors.paths' => ['api/*'],
            'cors.allowed_headers' => ['Authorization', 'Content-Type'], 'cors.supports_credentials' => false,
            'mail.default' => 'smtp', 'mail.mailers.password_reset' => ['transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.example.test',
                'port' => 587, 'require_tls' => true, 'username' => 'synthetic', 'password' => 'R6-PRIVATE-CANARY'],
            'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Synthetic Sender',
            'filesystems.media_disk' => 'public', 'filesystems.proof_disk' => 'proofs',
            'services.groq.key' => 'R6-PRIVATE-CANARY', 'services.groq.model' => 'synthetic-model', 'services.fcm.enabled' => false]);
        foreach (['r2_media' => 'lifeflow-media', 'r2_proofs' => 'lifeflow-proofs'] as $disk => $bucket) {
            config(['filesystems.disks.'.$disk => ['driver' => 's3', 'key' => 'R6-PRIVATE-CANARY', 'secret' => 'R6-PRIVATE-CANARY',
                'region' => 'auto', 'bucket' => $bucket, 'endpoint' => 'https://synthetic.r2.cloudflarestorage.com',
                'visibility' => 'private', 'directory_visibility' => 'private', 'serve' => false, 'use_path_style_endpoint' => true]]);
        }
    }

    protected function tearDown(): void
    {
        $this->app?->instance('env', 'testing');
        parent::tearDown();
    }

    public function test_synthetic_configuration_lint_never_claims_production_verification_or_probes_services(): void
    {
        $this->assertSame(0, Artisan::call('lifeflow:readiness', ['--json' => true]));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['configuration_valid']);
        $this->assertFalse($result['production_verified']);
        $this->assertCount(30, $result['checks']);
        $this->assertCount(10, array_filter($result['checks'], fn ($row) => $row['status'] === 'REVIEW'));
        foreach (['R6-PRIVATE-CANARY', 'sender@example.test', 'synthetic-db', 'api.example.test', base64_encode(str_repeat('A', 32))] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function unsafeUrls(): array
    {
        return [[null], [''], ['http://api.example.test'], ['https://localhost'], ['https://127.0.0.1'], ['https://127.1'],
            ['https://192.168.1.2'], ['https://10.1.2.3'], ['https://100.64.1.1'], ['https://198.18.0.1'], ['https://224.0.0.1'],
            ['https://[::1]'], ['https://[fd00::1]'],
            ['https://user:private@api.example.test'], ['https://api.example.test/api'], ['https://api.example.test?private=1'],
            ['https://api.example.test#private'], ['https://api.example.test:0'], ['https://api.example.test\\private']];
    }

    #[DataProvider('unsafeUrls')]
    public function test_missing_private_or_malformed_public_origins_are_blocked(mixed $url): void
    {
        config(['app.url' => $url]);
        $result = app(ProductionReadiness::class)->inspect();
        $this->assertFalse($result['configuration_valid']);
        $this->assertSame('BLOCKED', collect($result['checks'])->keyBy('code')['public_url']['status']);
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['app.debug', true, 'debug'], ['app.debug', 'false', 'debug'], ['app.key', '', 'encryption_keys'],
            ['app.key', 'base64:not-valid!', 'encryption_keys'], ['app.previous_keys', ['bad'], 'encryption_keys'],
            ['database.default', 'sqlite', 'database_driver'], ['database.connections.mysql', [], 'database_identity'],
            ['queue.default', 'sync', 'database_queue'], ['queue.connections.database.retry_after', 89, 'queue_reservation'],
            ['queue.connections.database.retry_after', '90', 'queue_reservation'], ['cache.default', 'array', 'shared_lock_store'],
            ['app.calendar_timezone', 'UTC', 'calendar'], ['cors.allowed_origins', ['*'], 'cors_origins'],
            ['cors.allowed_origins', [], 'cors_origins'], ['cors.allowed_origins', ['https://admin.example.test/'], 'cors_origins'],
            ['cors.allowed_origins_patterns', ['.*'], 'cors_origins'], ['cors.supports_credentials', true, 'cors_bearer'],
            ['cors.paths', [], 'cors_bearer'], ['cors.allowed_headers', [], 'cors_bearer'], ['mail.default', 'log', 'smtp'],
            ['mail.mailers.password_reset.require_tls', false, 'smtp'], ['mail.mailers.password_reset.password', null, 'smtp'],
            ['mail.mailers.password_reset.port', '1.5', 'smtp'], ['mail.from.address', 'invalid', 'mail_sender'],
            ['filesystems.media_disk', 'unknown', 'media_switch'], ['filesystems.proof_disk', 'public', 'proof_switch'],
            ['filesystems.disks.proofs.serve', true, 'proof_switch'], ['filesystems.disks.r2_media.visibility', 'public', 'r2_media'],
            ['filesystems.disks.r2_media.secret', '', 'r2_media'], ['filesystems.disks.r2_proofs.bucket', 'lifeflow-media', 'r2_proofs'],
            ['filesystems.disks.r2_proofs.endpoint', 'https://user:private@synthetic.r2.cloudflarestorage.com', 'r2_proofs'],
            ['filesystems.disks.r2_proofs.url', 'https://public.example.test', 'r2_proofs'], ['services.groq.key', null, 'groq'],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_unsafe_configuration_is_a_fixed_blocker_without_values(string $key, mixed $value, string $code): void
    {
        config([$key => $value]);
        $this->assertSame(1, Artisan::call('lifeflow:readiness', ['--json' => true]));
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('BLOCKED', collect($result['checks'])->keyBy('code')[$code]['status']);
        $this->assertStringNotContainsString('R6-PRIVATE-CANARY', $output);
    }

    public function test_current_nonproduction_and_enabled_but_missing_fcm_are_blocked(): void
    {
        $this->app->instance('env', 'testing');
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
        $result = collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('code');
        $this->assertSame('BLOCKED', $result['environment']['status']);
        $this->assertSame('BLOCKED', $result['fcm_configuration']['status']);
    }

    public function test_configured_fcm_does_not_read_the_credential_path_or_claim_native_delivery(): void
    {
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'synthetic-project',
            'services.fcm.credentials' => '/never-read/R6-PRIVATE-CANARY.json', 'app.previous_keys' => ['base64:'.base64_encode(str_repeat('B', 32))]]);
        $result = app(ProductionReadiness::class)->inspect();
        $this->assertTrue($result['configuration_valid']);
        $this->assertFalse($result['production_verified']);
        $this->assertSame('REVIEW', collect($result['checks'])->keyBy('code')['fcm_native_devices']['status']);
    }

    public function test_inspection_errors_are_generic_in_json_and_text(): void
    {
        $this->mock(ProductionReadiness::class)->shouldReceive('inspect')->twice()->andThrow(new RuntimeException('R6-PRIVATE-CANARY'));
        foreach ([true, false] as $json) {
            $this->assertSame(1, Artisan::call('lifeflow:readiness', ['--json' => $json]));
            $output = Artisan::output();
            $this->assertStringNotContainsString('R6-PRIVATE-CANARY', $output);
            $this->assertStringContainsString($json ? 'configuration_inspection_failed' : 'inspection failed', $output);
        }
    }
}
