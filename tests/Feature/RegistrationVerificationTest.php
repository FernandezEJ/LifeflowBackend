<?php

namespace Tests\Feature;

use App\Jobs\SendRegistrationVerificationCode;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\RegistrationVerificationService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $this->freezeTime();
    }

    private function data(array $changes = []): array
    {
        return array_replace(['first_name' => 'Test', 'middle_name' => null, 'last_name' => 'Donor', 'email' => 'new@example.com',
            'mobile_number' => '09171234567', 'birth_date' => '2004-09-13', 'gender' => 'Female', 'blood_type' => 'O+',
            'password' => 'password123', 'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
            'password_confirmation' => 'password123'], $changes);
    }

    private function pending(string $token): object
    {
        return DB::table('pending_registrations')->where('pending_token_hash', hash('sha256', $token))->first();
    }

    private function start(array $changes = []): array
    {
        $response = $this->postJson('/api/register/request-verification', $this->data($changes))->assertStatus(202);
        $token = $response->json('pending_token');
        app(RegistrationVerificationService::class)->deliver($this->pending($token)->request_id);

        return [$token, Mail::sent(RegistrationVerificationMail::class)->last()->code];
    }

    private function verify(string $token, string $code): TestResponse
    {
        return $this->postJson('/api/register/verify-email', ['pending_token' => $token, 'code' => $code]);
    }

    public function test_pending_details_are_encrypted_and_password_and_code_are_hashed_without_creating_accounts(): void
    {
        [$token,$code] = $this->start();
        $row = $this->pending($token);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('donor_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotSame($token, $row->pending_token_hash);
        $this->assertStringNotContainsString('new@example.com', $row->payload);
        $details = json_decode(Crypt::decryptString($row->payload), true);
        $this->assertArrayNotHasKey('password', $details);
        $this->assertArrayNotHasKey('password_confirmation', $details);
        $this->assertTrue(Hash::check('password123', $row->password_hash));
        $this->assertTrue(Hash::check(hash_hmac('sha256', $code, config('app.key')), $row->code_hash));
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $row->expires_at);
        Queue::assertPushed(SendRegistrationVerificationCode::class, fn ($job) => $job->queue === 'registration-verifications');
        $mail = Mail::sent(RegistrationVerificationMail::class)->first();
        $mail->assertHasSubject('Verify your LifeFlow email');
        $mail->assertSeeInText($code);
        $mail->assertSeeInText('This code expires in 15 minutes.');
    }

    public function test_final_verification_creates_profile_and_session_and_consumes_pending_payload(): void
    {
        [$token,$code] = $this->start();
        $response = $this->verify($token, $code)->assertCreated()->assertJsonPath('donor_profile.birth_date', '2004-09-13')
            ->assertJsonPath('donor_profile.mobile_number', '+639171234567')->assertJsonMissingPath('user.password');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 1);
        $this->assertNotNull(User::first()->email_verified_at);
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk();
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull($this->pending($token)->payload);
        $this->assertNull($this->pending($token)->password_hash);
        $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'password123'])->assertOk();
    }

    public function test_wrong_attempts_commit_and_fifth_blocks_correct_code(): void
    {
        [$token,$code] = $this->start();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 5; $i++) {
            $this->verify($token, $wrong)->assertUnprocessable();
            $this->assertSame($i, $this->pending($token)->attempt_count);
        }
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expiry_and_resend_replace_code_and_old_job_cannot_send_again(): void
    {
        [$token,$code] = $this->start();
        $old = $this->pending($token)->request_id;
        $this->postJson('/api/register/resend-verification', ['pending_token' => $token])->assertTooManyRequests();
        $this->travel(15)->minutes();
        $this->verify($token, $code)->assertUnprocessable();
        $this->postJson('/api/register/resend-verification', ['pending_token' => $token])->assertStatus(202);
        $this->assertNotSame($old, $this->pending($token)->request_id);
        $this->assertNull($this->pending($token)->code_hash);
        app(RegistrationVerificationService::class)->deliver($old);
        Mail::assertSentCount(1);
        $this->verify($token, $code)->assertUnprocessable();
        app(RegistrationVerificationService::class)->deliver($this->pending($token)->request_id);
        Mail::assertSentCount(2);
        $this->assertSame(0, $this->pending($token)->attempt_count);
        $this->verify($token, Mail::sent(RegistrationVerificationMail::class)->last()->code)->assertCreated();
    }

    public function test_request_validation_and_legacy_endpoint_cannot_bypass_verification(): void
    {
        $this->postJson('/api/register/request-verification', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile_number', 'birth_date']);
        $this->postJson('/api/register/request-verification', $this->data(['email' => 'invalid']))->assertUnprocessable();
        $this->postJson('/api/register/request-verification', $this->data(['birth_date' => now()->addDay()->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/register', $this->data())->assertStatus(202)->assertJsonMissingPath('token')->assertJsonMissingPath('code');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_rate_limit_normalizes_case_and_cannot_be_bypassed_by_legacy_route(): void
    {
        $this->postJson('/api/register/request-verification', $this->data())->assertStatus(202);
        $this->postJson('/api/register', $this->data(['email' => 'NEW@example.com']))->assertTooManyRequests();
        Queue::assertPushed(SendRegistrationVerificationCode::class, 1);
    }

    public function test_changed_address_and_new_request_cannot_use_previous_pending_token(): void
    {
        [$token,$code] = $this->start();
        [$other,$otherCode] = $this->start(['email' => 'changed@example.com']);
        $this->verify($other, $code === '000000' ? '111111' : '000000')->assertUnprocessable();
        $this->travel(60)->seconds();
        $replacement = $this->postJson('/api/register/request-verification', $this->data())->assertStatus(202)->json('pending_token');
        $this->assertNotSame($token, $replacement);
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_and_mobile_taken_after_request_fail_without_partial_account(): void
    {
        [$first,$firstCode] = $this->start();
        [$second,$secondCode] = $this->start(['email' => 'second@example.com']);
        $this->verify($first, $firstCode)->assertCreated();
        $this->verify($second, $secondCode)->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 1);
    }

    public function test_existing_email_and_mobile_are_rejected_and_old_accounts_remain_usable(): void
    {
        $user = User::factory()->create(['email' => 'new@example.com', 'password' => 'old-password', 'email_verified_at' => null]);
        $before = $user->fresh()->getAttributes();
        $this->postJson('/api/register/request-verification', $this->data())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password'])->assertOk();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_invalid_identifiers_and_code_format_are_rejected(): void
    {
        foreach ([['pending_token' => 'bad', 'code' => '123456'], ['pending_token' => str_repeat('a', 64), 'code' => 123456], ['pending_token' => str_repeat('a', 64), 'code' => '12345']] as $body) {
            $this->postJson('/api/register/verify-email', $body)->assertUnprocessable();
        }
        $this->postJson('/api/register/verify-email', ['pending_token' => str_repeat('a', 64), 'code' => '123456', 'email' => 'other@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_failed_mail_does_not_leave_usable_code_or_create_user(): void
    {
        $token = $this->postJson('/api/register/request-verification', $this->data())->assertStatus(202)->json('pending_token');
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('sensitive provider detail'));
        app(RegistrationVerificationService::class)->deliver($this->pending($token)->request_id);
        $this->assertNull($this->pending($token)->code_hash);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_taken_after_request_is_rechecked_at_final_creation(): void
    {
        [$token, $code] = $this->start();
        User::factory()->create(['email' => 'new@example.com']);
        $this->verify($token, $code)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 0);
        $this->assertNull($this->pending($token)->used_at);
    }

    public function test_two_simultaneous_verifications_create_one_account_and_session(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lifeflow-registration-test-');
        $workers = [];
        try {
            config(['database.connections.sqlite.database' => $file, 'database.connections.sqlite.busy_timeout' => 5000]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
            [$token, $code] = $this->start();
            $workerCode = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
                'database.connections.sqlite.busy_timeout' => 5000]);
            \Illuminate\Support\Facades\DB::purge('sqlite');
            echo "ready\n"; fflush(STDOUT); fgets(STDIN);
            $request = \Illuminate\Http\Request::create('/api/register/verify-email', 'POST', [], [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $argv[2]);
            $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
            echo $response->getStatusCode();
            PHP;
            $streams = [];
            for ($i = 0; $i < 2; $i++) {
                $stream = new InputStream;
                $worker = new Process([PHP_BINARY, '-r', $workerCode, $file,
                    json_encode(['pending_token' => $token, 'code' => $code])], base_path());
                $worker->setInput($stream);
                $worker->setTimeout(25);
                $worker->start();
                $workers[] = $worker;
                $streams[] = $stream;
            }
            $deadline = microtime(true) + 15;
            foreach ($workers as $worker) {
                while (! str_contains($worker->getOutput(), 'ready') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $worker->getOutput(), $worker->getErrorOutput());
            }
            foreach ($streams as $stream) {
                $stream->write("go\n");
                $stream->close();
            }
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = (int) trim(str_replace('ready', '', $worker->getOutput()));
            }
            sort($statuses);
            $this->assertSame([201, 422], $statuses);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('donor_profiles', 1);
            $this->assertDatabaseCount('personal_access_tokens', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_all_consents_are_strict_booleans_before_any_email_is_queued(): void
    {
        foreach (RegistrationVerificationService::CONSENT_FIELDS as $field) {
            foreach ([null, false, 0, 1, 'true', '1', 'yes', [], true] as $index => $value) {
                $data = $this->data([$field => $value]);
                if ($value === null) {
                    unset($data[$field]);
                }
                // Separate source addresses exercise validation without hitting the independent IP cap.
                $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(10 + $index)]);
                if ($value === true) {
                    continue;
                }
                $this->postJson('/api/register/request-verification', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('pending_registrations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_consent_is_recorded_atomically_with_server_time_and_versions(): void
    {
        [$token, $code] = $this->start();
        $this->assertDatabaseCount('user_consents', 0);
        $this->travel(2)->minutes();
        $this->verify($token, $code)->assertCreated();
        $row = DB::table('user_consents')->first();
        $this->assertSame(User::first()->id, $row->user_id);
        $this->assertSame(1, $row->accepted_terms);
        $this->assertSame(1, $row->acknowledged_privacy);
        $this->assertSame(1, $row->acknowledged_prescreening);
        $this->assertSame('1.0', $row->terms_version);
        $this->assertSame('1.0', $row->privacy_version);
        $this->assertSame(now()->toDateTimeString(), $row->accepted_at);
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertDatabaseCount('user_consents', 1);
    }

    public function test_client_cannot_select_consent_timestamp_or_versions(): void
    {
        $this->postJson('/api/register/request-verification', $this->data([
            'accepted_at' => '2000-01-01', 'terms_version' => 'fake', 'privacy_version' => 'fake',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['accepted_at', 'terms_version', 'privacy_version']);
        Queue::assertNothingPushed();
    }

    public function test_older_pending_payload_cannot_verify_resend_or_deliver_without_consent(): void
    {
        [$token, $code] = $this->start();
        $row = $this->pending($token);
        $data = json_decode(Crypt::decryptString($row->payload), true);
        foreach (RegistrationVerificationService::CONSENT_FIELDS as $field) {
            unset($data[$field]);
        }
        DB::table('pending_registrations')->where('pending_token_hash', hash('sha256', $token))->update([
            'payload' => Crypt::encryptString(json_encode($data)), 'delivery_claimed_at' => null,
        ]);
        $this->verify($token, $code)->assertUnprocessable()->assertJsonValidationErrors('pending_token');
        $this->travel(60)->seconds();
        $this->postJson('/api/register/resend-verification', ['pending_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('pending_token');
        app(RegistrationVerificationService::class)->deliver($row->request_id);
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_consents', 0);
    }
}
