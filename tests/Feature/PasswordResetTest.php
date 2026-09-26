<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'sender@example.test',
            'mail.mailers.password_reset.password' => 'isolated-test-placeholder',
        ]);
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        Mail::fake();
        $this->freezeTime();
    }

    private function row(string $email): ?object
    {
        return DB::table('password_reset_codes')->where('email_hash', hash('sha256', strtolower($email)))->first();
    }

    private function requestCode(User $user): string
    {
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        $row = $this->row($user->email);
        app(PasswordResetService::class)->deliver(strtolower($user->email), $row->request_id);
        $mail = Mail::sent(PasswordResetCodeMail::class)->last();
        $this->assertNotNull($mail);

        return $mail->code;
    }

    private function authorization(User $user): string
    {
        $code = $this->requestCode($user);

        return $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('reset_token');
    }

    private function resetBody(User $user, string $token): array
    {
        return ['email' => $user->email, 'reset_token' => $token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
    }

    public function test_requests_validate_email_and_reject_extra_inputs(): void
    {
        foreach ([[], ['email' => 'invalid'], ['email' => ['array']]] as $payload) {
            $this->postJson('/api/forgot-password/request', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/forgot-password/request', ['email' => 'valid@example.com', 'user_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('password_reset_codes', 0);
    }

    public function test_known_and_unknown_addresses_get_identical_neutral_queued_responses(): void
    {
        $user = User::factory()->create();
        $expected = ['message' => PasswordResetService::NOTICE, 'resend_after' => 60];
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->postJson('/api/forgot-password/request', ['email' => $email])->assertStatus(202)->assertExactJson($expected);
            $this->assertNotNull($this->row($email));
        }
        Queue::assertPushed(SendPasswordResetCode::class, 2);
        Queue::assertPushed(SendPasswordResetCode::class, fn ($job) => $job instanceof ShouldBeEncrypted && $job->queue === 'password-resets' && $job->connection === 'database');
        Mail::assertNothingSent();
        app(PasswordResetService::class)->deliver('unknown@example.com', $this->row('unknown@example.com')->request_id);
        Mail::assertNothingSent();
        $this->assertNotNull($this->row('unknown@example.com')->used_at);
    }

    public function test_worker_sends_six_digits_and_stores_only_hash_with_fifteen_minute_expiry(): void
    {
        $user = User::factory()->create(['email' => 'Mixed@example.com']);
        $code = $this->requestCode($user);
        $row = $this->row($user->email);
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check(hash_hmac('sha256', $code, config('app.key')), $row->code_hash));
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $row->expires_at);
        $this->assertSame($user->id, $row->user_id);
        $mail = Mail::sent(PasswordResetCodeMail::class)->first();
        $mail->assertTo('mixed@example.com');
        $mail->assertHasSubject('Your LifeFlow password reset code');
        $mail->assertSeeInText($code);
        $mail->assertSeeInText('This code expires in 15 minutes.');
        $mail->assertSeeInText('If you did not request a password reset');
        $this->assertNull($row->reset_token_hash);
        app(PasswordResetService::class)->deliver(strtolower($user->email), $row->request_id);
        Mail::assertSentCount(1);
    }

    public function test_per_email_cooldown_normalizes_case_and_resend_replaces_prior_authorization(): void
    {
        $user = User::factory()->create();
        $token = $this->authorization($user);
        $first = (array) $this->row($user->email);
        $this->postJson('/api/forgot-password/request', ['email' => ' '.strtoupper($user->email).' '])->assertStatus(202);
        $this->assertSame($first, (array) $this->row($user->email));
        Queue::assertPushed(SendPasswordResetCode::class, 1);
        $this->travel(60)->seconds();
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        $new = $this->row($user->email);
        $this->assertNotSame($first['request_id'], $new->request_id);
        $this->assertNull($new->reset_token_hash);
        $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token))->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        app(PasswordResetService::class)->deliver($user->email, $first['request_id']);
        Mail::assertSentCount(1);
        $this->assertNull($this->row($user->email)->code_hash);
        app(PasswordResetService::class)->deliver($user->email, $new->request_id);
        Mail::assertSentCount(2);
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    public function test_ip_request_limit_also_applies_to_unknown_emails(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password/request', ['email' => 'unknown'.$i.'@example.com'])->assertStatus(202);
        }
        $this->postJson('/api/forgot-password/request', ['email' => 'another@example.com'])->assertTooManyRequests();
        Queue::assertPushed(SendPasswordResetCode::class, 5);
    }

    public function test_verify_requires_exact_six_digit_string(): void
    {
        foreach (['12345', '1234567', 'abcdef', 123456] as $code) {
            $this->postJson('/api/forgot-password/verify', ['email' => 'a@example.com', 'code' => $code])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/forgot-password/verify', ['email' => 'a@example.com', 'code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_five_wrong_attempts_commit_counter_and_invalidate_even_correct_code(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $wrong])->assertUnprocessable();
            $this->assertSame($i, $this->row($user->email)->attempt_count);
        }
        $this->assertNotNull($this->row($user->email)->used_at);
        $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertNull($this->row($user->email)->reset_token_hash);
    }

    public function test_code_expires_at_exact_fifteen_minute_boundary(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);
        $this->travel(15)->minutes();
        $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertNull($this->row($user->email)->verified_at);
    }

    public function test_verification_issues_hashed_ten_minute_token_and_consumes_code(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);
        $this->travel(14)->minutes();
        $response = $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk()->assertJsonPath('expires_in', 600)->assertJsonMissingPath('code');
        $token = $response->json('reset_token');
        $row = $this->row($user->email);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $this->assertSame(hash('sha256', $token), $row->reset_token_hash);
        $this->assertNotSame($token, $row->reset_token_hash);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $row->reset_token_expires_at);
        $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->travel(2)->minutes();
        $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token))->assertOk();
    }

    public function test_reset_rejects_missing_invalid_and_other_account_authorization(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->authorization($user);
        foreach (['', '123456', str_repeat('a', 64)] as $invalid) {
            $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $invalid))->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        }
        $this->postJson('/api/forgot-password/reset', $this->resetBody($other, $token))->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        $this->assertNull($this->row($user->email)->used_at);
    }

    public function test_password_validation_keeps_authorization_usable(): void
    {
        $user = User::factory()->create();
        $token = $this->authorization($user);
        $body = $this->resetBody($user, $token);
        $missing = $body;
        unset($missing['password_confirmation']);
        $this->postJson('/api/forgot-password/reset', $missing)->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
        $this->postJson('/api/forgot-password/reset', array_replace($body, ['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/forgot-password/reset', array_replace($body, ['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/forgot-password/reset', $body + ['user_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertNull($this->row($user->email)->used_at);
        $this->postJson('/api/forgot-password/reset', $body)->assertOk();
    }

    public function test_reset_expiry_is_server_authoritative_at_exact_ten_minutes(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $token = $this->authorization($user);
        $this->travel(10)->minutes();
        $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token))->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_success_revokes_all_owned_sessions_and_device_tokens_and_requires_new_login(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $other = User::factory()->create();
        $oldTokens = [$user->createToken('phone'), $user->createToken('tablet')];
        $otherToken = $other->createToken('other');
        DB::table('device_tokens')->insert([
            'user_id' => $user->id, 'personal_access_token_id' => $oldTokens[0]->accessToken->id,
            'token' => 'test-device', 'token_hash' => hash('sha256', 'test-device'),
            'platform' => 'android', 'provider' => 'fcm', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->authorization($user);
        $response = $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token));
        $response->assertOk()->assertExactJson(['message' => 'Your password has been updated successfully.']);
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseCount('device_tokens', 0);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        foreach ($oldTokens as $oldToken) {
            $this->app['auth']->forgetGuards();
            $this->withToken($oldToken->plainTextToken)->getJson('/api/user')->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'original-password'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password-123'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token))->assertUnprocessable();
        $this->assertNotNull($this->row($user->email)->used_at);
        $this->assertNull($this->row($user->email)->reset_token_hash);
    }

    public function test_mail_failure_is_safe_and_invalidates_unsent_state(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        Mail::shouldReceive('mailer')->with('password_reset')->once()->andThrow(new \RuntimeException('SECRET SMTP FAILURE CONTENT'));
        Log::spy();
        app(PasswordResetService::class)->deliver($user->email, $this->row($user->email)->request_id);
        $this->assertNotNull($this->row($user->email)->used_at);
        $this->assertNull($this->row($user->email)->code_hash);
        Log::shouldHaveReceived('error')->once()->with('Password reset email delivery failed. Check SMTP configuration and connectivity.');
    }

    public function test_missing_smtp_does_not_fall_back_to_logging_reset_code(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'log', 'mail.mailers.password_reset.password' => null]);
        Log::spy();
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        app(PasswordResetService::class)->deliver($user->email, $this->row($user->email)->request_id);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('error')->once()->with('Password reset email is not configured. Configure SMTP host, credentials and sender.');
        $this->assertNotNull($this->row($user->email)->used_at);
        $this->assertNull($this->row($user->email)->code_hash);
    }

    public function test_delayed_worker_does_not_send_expired_request(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        $this->travel(15)->minutes();
        app(PasswordResetService::class)->deliver($user->email, $this->row($user->email)->request_id);
        Mail::assertNothingSent();
        $this->assertNull($this->row($user->email)->code_hash);
    }

    public function test_verification_and_reset_have_separate_ip_limits(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/forgot-password/verify', ['email' => 'unknown@example.com', 'code' => '123456'])->assertUnprocessable();
        }
        $this->postJson('/api/forgot-password/verify', ['email' => 'unknown@example.com', 'code' => '123456'])->assertTooManyRequests();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/forgot-password/reset', [])->assertUnprocessable();
        }
        $this->postJson('/api/forgot-password/reset', [])->assertTooManyRequests();
    }

    public function test_bcrypt_length_and_null_bytes_are_rejected_without_consuming_authorization(): void
    {
        $user = User::factory()->create();
        $token = $this->authorization($user);
        foreach ([str_repeat('a', 73), "password\0invalid"] as $password) {
            $this->postJson('/api/forgot-password/reset', array_replace($this->resetBody($user, $token), [
                'password' => $password, 'password_confirmation' => $password,
            ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertNull($this->row($user->email)->used_at);
        $password = str_repeat('a', 72);
        $this->postJson('/api/forgot-password/reset', array_replace($this->resetBody($user, $token), [
            'password' => $password, 'password_confirmation' => $password,
        ]))->assertOk();
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_failure_during_revocation_rolls_back_password_and_authorization(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $user->createToken('phone');
        $token = $this->authorization($user);
        DB::statement("CREATE TRIGGER stop_reset_revocation BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'isolated rollback check'); END");
        try {
            app(PasswordResetService::class)->reset($user->email, $token, 'new-password-123');
            $this->fail('Expected a database failure.');
        } catch (QueryException) {
            $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
            $this->assertNull($this->row($user->email)->used_at);
            $this->assertSame(1, $user->tokens()->count());
        }
        DB::statement('DROP TRIGGER stop_reset_revocation');
        $this->postJson('/api/forgot-password/reset', $this->resetBody($user, $token))->assertOk();
    }

    public function test_database_queue_encrypts_address_and_never_persists_plaintext_code(): void
    {
        Queue::swap($this->realQueue);
        $user = User::factory()->create();
        $this->postJson('/api/forgot-password/request', ['email' => $user->email])->assertStatus(202);
        $queued = DB::table('jobs')->first();
        $this->assertNotNull($queued);
        $this->assertSame('password-resets', $queued->queue);
        $this->assertStringNotContainsString($user->email, $queued->payload);
        $data = json_decode($queued->payload, true);
        $serialized = $this->app['encrypter']->decrypt($data['data']['command']);
        $job = unserialize($serialized);
        $this->assertInstanceOf(SendPasswordResetCode::class, $job);
        $this->assertSame($user->email, $job->email);
        $this->assertNull($this->row($user->email)->code_hash);
        $this->assertSame(['email', 'requestId'], array_values(array_filter(array_keys((array) $job), fn ($key) => in_array($key, ['email', 'requestId', 'code']))));
        $job->handle(app(PasswordResetService::class));
        Mail::assertSentCount(1);
    }
}
