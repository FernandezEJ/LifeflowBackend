<?php

namespace Tests\Feature;

use App\Jobs\SendDonorLoginCode;
use App\Jobs\SendEmailChangeCode;
use App\Jobs\SendPasswordResetCode;
use App\Jobs\SendRegistrationVerificationCode;
use App\Models\User;
use App\Services\AccountSettingsService;
use App\Services\AuthMailConfiguration;
use App\Services\BrevoAuthTransport;
use App\Services\DonorLoginService;
use App\Services\PasswordResetService;
use App\Services\ProductionReadiness;
use App\Services\RegistrationVerificationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BrevoAuthMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        config(['mail.default' => 'array', 'mail.mailers.password_reset.transport' => 'brevo',
            'mail.mailers.password_reset.host' => null, 'mail.mailers.password_reset.username' => null,
            'mail.mailers.password_reset.password' => null, 'services.brevo.key' => 'synthetic-private-canary',
            'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'LifeFlow Test']);
    }

    /** @return array{\Closure, string, string, class-string} */
    private function issue(string $flow): array
    {
        if ($flow === 'registration') {
            app(RegistrationVerificationService::class)->request([
                'first_name' => 'Test', 'last_name' => 'Donor', 'middle_name' => null,
                'email' => 'new@example.test', 'mobile_number' => '09171234567',
                'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+',
                'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
                'privacy_version' => '1.1',
            ]);
            $row = DB::table('pending_registrations')->first();

            return [fn () => app(RegistrationVerificationService::class)->deliver($row->request_id),
                'pending_registrations', 'registration-verifications', SendRegistrationVerificationCode::class];
        }
        $user = User::factory()->create(['email' => 'donor@example.test', 'password' => 'password123']);
        $user->donorProfile()->create(['first_name' => 'Test', 'last_name' => 'Donor', 'mobile_number' => '+639171234567',
            'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);
        if ($flow === 'login') {
            app(DonorLoginService::class)->request($user->email);
            $row = DB::table('donor_login_codes')->first();

            return [fn () => app(DonorLoginService::class)->deliver($row->request_id, $user->email),
                'donor_login_codes', 'donor-logins', SendDonorLoginCode::class];
        }
        if ($flow === 'email-change') {
            $user->withAccessToken($user->createToken('mobile', ['*'], now()->addDay())->accessToken);
            app(AccountSettingsService::class)->request($user, 'password123', 'changed@example.test');
            $row = DB::table('email_changes')->first();

            return [fn () => app(AccountSettingsService::class)->deliver($row->request_id),
                'email_changes', 'email-changes', SendEmailChangeCode::class];
        }
        $staff = $flow === 'staff-password';
        if ($staff) {
            $user->forceFill(['role' => 'admin'])->save();
        }
        app(PasswordResetService::class)->requestCode($user->email, $staff);
        $row = DB::table('password_reset_codes')->first();

        return [fn () => app(PasswordResetService::class)->deliver($user->email, $row->request_id, $staff),
            'password_reset_codes', 'password-resets', SendPasswordResetCode::class];
    }

    public static function pathways(): array
    {
        $cases = [];
        foreach (['login', 'registration', 'donor-password', 'staff-password', 'email-change'] as $flow) {
            foreach (['accepted', 'rate-limit', 'server-error', 'timeout', 'malformed'] as $outcome) {
                $cases[$flow.' '.$outcome] = [$flow, $outcome];
            }
        }

        return $cases;
    }

    #[DataProvider('pathways')]
    public function test_existing_jobs_send_once_through_named_mailer_and_fail_closed(string $flow, string $outcome): void
    {
        Log::spy();
        $requests = 0;
        $captured = [];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$requests, &$captured, $outcome) {
            $requests++;
            $captured = [$method, $url, $options];
            if ($outcome === 'timeout') {
                throw new TimeoutException('synthetic-private-canary donor@example.test private-code');
            }

            return new MockResponse(match ($outcome) {
                'accepted' => '{"messageId":"<accepted@example.test>"}',
                'malformed' => '{"messageId":false}',
                default => '{"message":"synthetic-private-canary donor@example.test private-code"}',
            }, ['http_code' => match ($outcome) {
                'rate-limit' => 429, 'server-error' => 503, default => 201,
            }, 'debug' => 'synthetic-private-canary private-code']);
        });
        $this->app->bind(BrevoAuthTransport::class, fn () => new BrevoAuthTransport($client));
        [$deliver, $table, $queue, $job] = $this->issue($flow);
        Queue::assertPushed($job, fn ($work) => $work instanceof ShouldBeEncrypted && $work->queue === $queue
            && $work->connection === 'database' && $work->tries === 1 && $work->timeout === 45);
        $deliver();
        $deliver();
        $this->assertSame(1, $requests, 'Even ambiguous outcomes must never be replayed.');
        [$method, $url, $options] = $captured;
        $this->assertSame('POST', $method);
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $url);
        $this->assertSame(0, $options['max_redirects']);
        $this->assertSame(10.0, $options['max_duration']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_host']);
        $this->assertContains('api-key: synthetic-private-canary', $options['headers']);
        $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['email' => 'sender@example.test', 'name' => $flow === 'login' ? 'LifeFlow' : 'LifeFlow Test'], $payload['sender']);
        $this->assertSame($flow === 'registration' ? 'new@example.test' : ($flow === 'email-change' ? 'changed@example.test' : 'donor@example.test'), $payload['to'][0]['email']);
        $this->assertNotEmpty($payload['subject']);
        $content = $payload['textContent'];
        $this->assertStringContainsString('LifeFlow', $content);
        $this->assertMatchesRegularExpression('/\b[0-9]{6}\b/', $content);
        $this->assertStringContainsString('LifeFlow', $payload['htmlContent']);
        $this->assertStringContainsString('role="presentation"', $payload['htmlContent']);
        $this->assertStringContainsString('This code expires in '.($flow === 'login' ? '10' : '15').' minutes.', $payload['htmlContent']);
        $row = DB::table($table)->first();
        if ($outcome === 'accepted') {
            $this->assertNotEmpty($row->code_hash);
            Log::shouldNotHaveReceived('error');
        } else {
            $this->assertNull($row->code_hash);
            Log::shouldHaveReceived('error')->once()->with(\Mockery::on(fn ($message) => ! str_contains($message, 'synthetic-private-canary') && ! str_contains($message, '@') && ! str_contains($message, 'private-code')));
        }
    }

    public static function malformedResponses(): array
    {
        return [['not-json', 201], ['{}', 201], ['[]', 201], ['{"messageId":123}', 201],
            ['{"messageId":""}', 201], ['{"messageId":"\\r\\nsecret"}', 201],
            ['{"messageId":"unexpected"}', 200], ['{"message":"private-code"}', 401],
            ['not-json', 502], ['{"message":"private-code"}', 302]];
    }

    #[DataProvider('malformedResponses')]
    public function test_provider_failures_have_no_sensitive_debug_or_previous_exception(string $body, int $status): void
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status, 'debug' => 'synthetic-private-canary']));
        try {
            (new BrevoAuthTransport($client))->send((new Email)->from('sender@example.test')->to('donor@example.test')->subject('Synthetic')->text('private-code'));
            $this->fail('The malformed/provider response must fail closed.');
        } catch (TransportException $e) {
            $this->assertSame('Authentication email submission failed; delivery is unconfirmed.', $e->getMessage());
            $this->assertSame('', $e->getDebug());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('private-code', (string) $e);
            $this->assertStringNotContainsString('synthetic-private-canary', (string) $e);
            $this->assertSame(1, $client->getRequestsCount());
        }
    }

    public function test_success_exposes_acceptance_without_http_debug_or_global_mailer_change(): void
    {
        $client = new MockHttpClient(new MockResponse('{"messageId":"<accepted@example.test>"}', ['http_code' => 201, 'debug' => 'synthetic-private-canary']));
        $this->app->bind(BrevoAuthTransport::class, fn () => new BrevoAuthTransport($client));
        $this->assertSame('brevo+api://api.brevo.com', (string) Mail::mailer('password_reset')->getSymfonyTransport());
        $sent = Mail::mailer('password_reset')->raw('Synthetic message', fn ($mail) => $mail->to('donor@example.test')->subject('Synthetic'));
        $this->assertSame('', $sent->getSymfonySentMessage()->getDebug());
        $this->assertSame('array', config('mail.default'));
        Mail::raw('Unrelated mail', fn ($mail) => $mail->to('donor@example.test'));
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function test_smtp_resolves_without_any_brevo_request_and_keeps_tls_settings(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('SMTP must never invoke Brevo.'));
        $this->app->bind(BrevoAuthTransport::class, fn () => new BrevoAuthTransport($client));
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.transport' => 'smtp',
            'mail.mailers.password_reset.host' => 'smtp.example.test', 'mail.mailers.password_reset.port' => 587,
            'mail.mailers.password_reset.username' => 'synthetic', 'mail.mailers.password_reset.password' => 'synthetic',
            'mail.mailers.password_reset.scheme' => 'smtp', 'services.brevo.key' => null]);
        $this->assertTrue(AuthMailConfiguration::configured());
        $this->assertTrue(config('mail.mailers.password_reset.require_tls'));
        $this->assertInstanceOf(EsmtpTransport::class, Mail::mailer('password_reset')->getSymfonyTransport());
        $this->assertTrue(Mail::mailer('password_reset')->getSymfonyTransport()->isTlsRequired());
        $this->assertSame(0, $client->getRequestsCount());
    }

    public static function invalidKeys(): array
    {
        return [[null], [''], ['   '], ["invalid\r\nkey"], [false], [[]]];
    }

    #[DataProvider('invalidKeys')]
    public function test_missing_or_invalid_secret_fails_before_provider_resolution(mixed $key): void
    {
        config(['services.brevo.key' => $key]);
        $this->assertFalse(AuthMailConfiguration::configured());
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Authentication email transport is not configured.');
        new BrevoAuthTransport(new MockHttpClient(fn () => $this->fail('No request is allowed.')));
    }

    public function test_unknown_and_failover_transports_cannot_send_auth_mail(): void
    {
        foreach (['log', 'array', 'failover', 'roundrobin', 'unknown'] as $transport) {
            config(['mail.mailers.password_reset.transport' => $transport]);
            $this->assertFalse(AuthMailConfiguration::configured());
        }
    }

    public function test_config_files_default_to_smtp_and_support_exported_cached_brevo_values(): void
    {
        // Read only PHP config definitions in fresh processes; never cache the real app's secrets.
        foreach ([null => 'smtp', 'smtp' => 'smtp', 'brevo' => 'brevo'] as $selection => $expected) {
            $code = <<<'PHP'
require 'vendor/autoload.php';
$mail = require 'config/mail.php';
$services = require 'config/services.php';
$cached = eval('return '.var_export(['mail' => $mail, 'services' => ['brevo' => $services['brevo']]], true).';');
echo json_encode([$cached['mail']['mailers']['password_reset']['transport'], $cached['mail']['default'],
    $cached['services']['brevo']['key'] === 'synthetic-cache-canary', $cached['mail']['mailers']['password_reset']['require_tls']]);
PHP;
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                'LIFEFLOW_AUTH_MAIL_TRANSPORT' => $selection === '' ? false : $selection,
                'MAIL_MAILER' => 'array', 'BREVO_API_KEY' => 'synthetic-cache-canary',
            ]);
            $process->mustRun();
            $this->assertSame([$expected, 'array', true, true], json_decode($process->getOutput(), true));
        }
        $cached = eval('return '.var_export(['mail.mailers.password_reset' => config('mail.mailers.password_reset'),
            'services.brevo.key' => config('services.brevo.key')], true).';');
        config($cached);
        $client = new MockHttpClient(new MockResponse('{"messageId":"<accepted@example.test>"}', ['http_code' => 201]));
        $this->assertTrue(AuthMailConfiguration::configured());
        $this->assertNotNull((new BrevoAuthTransport($client))->send((new Email)->from('sender@example.test')->to('donor@example.test')->text('Synthetic')));
    }

    public function test_readiness_recognizes_brevo_without_reading_or_reporting_secrets_or_sending(): void
    {
        $result = app(ProductionReadiness::class)->inspect();
        $this->assertSame('PASS', collect($result['checks'])->keyBy('code')['smtp']['status']);
        $this->assertFalse($result['production_verified']);
        $this->assertStringNotContainsString('synthetic-private-canary', json_encode($result));
        config(['services.brevo.key' => null]);
        $this->assertSame('BLOCKED', collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('code')['smtp']['status']);
    }

    public function test_missing_key_invalidates_existing_challenge_without_request_or_replay(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('No external request is allowed.'));
        $this->app->bind(BrevoAuthTransport::class, fn () => new BrevoAuthTransport($client));
        config(['services.brevo.key' => null]);
        [$deliver, $table] = $this->issue('login');
        $deliver();
        $deliver();
        $this->assertNull(DB::table($table)->first()->code_hash);
        $this->assertNotNull(DB::table($table)->first()->delivery_claimed_at);
        $this->assertSame(0, $client->getRequestsCount());
    }
}
