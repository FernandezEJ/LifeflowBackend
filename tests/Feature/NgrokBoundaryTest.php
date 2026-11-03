<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class NgrokBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->loadCors('production', 'https://admin.example.test,https://preview.admin.example.test,https://admin-side.pages.dev');
    }

    private function loadCors(string $environment, string $origins, string $localOrigins = ''): void
    {
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        try {
            foreach (['APP_ENV' => $environment, 'CORS_ALLOWED_ORIGINS' => $origins, 'CORS_LOCAL_ALLOWED_ORIGINS' => $localOrigins] as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            config(['cors' => require config_path('cors.php')]);
        } finally {
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
        }
    }

    public static function approvedOrigins(): array
    {
        return [['https://admin.example.test'], ['https://preview.admin.example.test'], ['https://admin-side.pages.dev']];
    }

    #[DataProvider('approvedOrigins')]
    public function test_explicit_browser_origins_can_preflight_bearer_api_requests(string $origin): void
    {
        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,ngrok-skip-browser-warning',
        ])->options('/api/admin/login');

        $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $headers = strtolower($response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $headers);
        $this->assertStringContainsString('content-type', $headers);
        $this->assertStringContainsString('ngrok-skip-browser-warning', $headers);
    }

    public static function deniedOrigins(): array
    {
        return [['https://other.example.test'], ['https://admin.example.test.attacker.test'],
            ['http://admin.example.test'], ['https://arbitrary.pages.dev'], ['null']];
    }

    #[DataProvider('deniedOrigins')]
    public function test_unapproved_browser_preflights_receive_no_origin_permission(string $origin): void
    {
        $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,ngrok-skip-browser-warning'])
            ->options('/api/admin/me')->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_missing_allowlist_fails_closed_and_local_origins_are_environment_scoped(): void
    {
        $this->loadCors('production', '', 'http://localhost:5173');
        $this->assertSame([], config('cors.allowed_origins'));
        $this->loadCors('local', '', 'http://localhost:5173,http://127.0.0.1:5174,https://remote.example.test');
        $this->assertSame(['http://localhost:5173', 'http://127.0.0.1:5174'], config('cors.allowed_origins'));
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/admin/me')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_configuration_rejects_wildcards_and_non_origin_values(): void
    {
        $this->loadCors('production', '*,https://*.pages.dev,http://admin.example.test,https://admin.example.test/,'
            .'https://user:secret@admin.example.test,https://admin.example.test?query=1,https://admin.example.test#fragment,'
            .'https://admin.example.test:0, https://admin.example.test,https://admin.example.test');
        $this->assertSame(['https://admin.example.test'], config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_cors_does_not_authenticate_or_block_native_requests_without_an_origin(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeaders(['Origin' => 'https://admin.example.test'])->getJson('/api/admin/me')
            ->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.test');
    }

    public function test_forwarded_headers_from_an_untrusted_peer_cannot_spoof_ip_scheme_or_host(): void
    {
        Route::get('/api/boundary-test', fn (Request $request): array => [
            'ip' => $request->ip(), 'secure' => $request->isSecure(), 'host' => $request->getHost(),
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders([
            'Host' => 'api.example.test', 'X-Forwarded-For' => '203.0.113.1, 198.51.100.1',
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example.test',
            'Forwarded' => 'for=203.0.113.1;proto=https;host=attacker.example.test',
        ])->getJson('http://api.example.test/api/boundary-test')
            ->assertOk()->assertExactJson(['ip' => '127.0.0.1', 'secure' => false, 'host' => 'api.example.test']);
    }

    public function test_api_errors_do_not_expose_debug_details_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/boundary-error-test', function (): Response {
            throw new \RuntimeException('PRIVATE-DEBUG-CANARY');
        });
        $response = $this->getJson('/api/boundary-error-test')->assertInternalServerError();
        $response->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
        $this->assertStringNotContainsString('PRIVATE-DEBUG-CANARY', $response->getContent());
    }
}
