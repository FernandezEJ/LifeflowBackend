<?php

namespace Tests\Feature;

use App\Jobs\SendDonorLoginCode;
use App\Mail\DonorLoginCodeMail;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\DonorLoginService;
use App\Services\RegistrationVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonorPasswordlessTest extends TestCase
{
    private const MOBILE = '+639171234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test', 'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00+08:00'));
    }

    private function donor(string $mobile = self::MOBILE): User
    {
        $user = User::factory()->create();
        $user->donorProfile()->create(['first_name' => 'Test', 'middle_name' => 'Santos', 'last_name' => 'Donor', 'mobile_number' => $mobile, 'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);

        return $user;
    }

    private function row(): object
    {
        return DB::table('donor_login_codes')->where('mobile_hash', hash('sha256', self::MOBILE))->first();
    }

    private function issue(string $mobile = self::MOBILE): string
    {
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => $mobile])->assertStatus(202);
        (new SendDonorLoginCode($this->row()->request_id, self::MOBILE))->handle(app(DonorLoginService::class));

        return Mail::sent(DonorLoginCodeMail::class)->last()->code;
    }

    private function verify(string $code, string $mobile = self::MOBILE): TestResponse
    {
        return $this->postJson('/api/auth/verify-login-code', ['mobile_number' => $mobile, 'code' => $code]);
    }

    private function registration(array $changes = []): array
    {
        return array_replace(['first_name' => 'New', 'middle_name' => null, 'last_name' => 'Donor', 'email' => 'new@example.test', 'mobile_number' => '09181112222', 'birth_date' => '2008-10-01', 'gender' => 'Male', 'blood_type' => 'O+', 'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true], $changes);
    }

    public function test_generic_request_secure_storage_queue_and_real_one_time_session(): void
    {
        $donor = $this->donor();
        $other = $donor->createToken('other-device');
        $knownResponse = $this->postJson('/api/auth/request-login-code', ['mobile_number' => self::MOBILE])->assertStatus(202);
        $this->assertStringContainsString('no-store', $knownResponse->headers->get('Cache-Control'));
        $known = $knownResponse->json();
        $unknown = $this->postJson('/api/auth/request-login-code', ['mobile_number' => '+639181111111'])->assertStatus(202)->json();
        $this->assertSame($known, $unknown);
        $this->assertSame(['message', 'resend_after', 'expires_in'], array_keys($known));
        app(DonorLoginService::class)->deliver($this->row()->request_id, self::MOBILE);
        $mail = Mail::sent(DonorLoginCodeMail::class)->sole();
        $code = $mail->code;
        $this->assertNotSame($code, $this->row()->code_hash);
        $this->assertTrue(Hash::check(hash_hmac('sha256', 'donor_login:'.$code, config('app.key')), $this->row()->code_hash));
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $this->row()->expires_at);
        Queue::assertPushed(SendDonorLoginCode::class, fn ($job) => $job instanceof ShouldBeEncrypted && $job->queue === 'donor-logins' && ! str_contains(serialize($job), $code));
        $mail->assertHasTo($donor->email);
        $mail->assertHasSubject('Your LifeFlow Login Code');
        $mail->assertSeeInText('expires in 10 minutes');
        $session = $this->verify($code)->assertOk()->assertJsonPath('user.id', $donor->id)->assertJsonMissingPath('user.password')->json('token');
        $this->assertNotNull($this->row()->used_at);
        $this->assertNull($this->row()->code_hash);
        $this->verify($code)->assertUnprocessable();
        $this->withToken($session)->getJson('/api/user')->assertJsonPath('id', $donor->id);
        foreach (['donor-profile', 'donation-participations', 'points/summary', 'eligibility-assessments/latest', 'announcements'] as $path) {
            $this->getJson('/api/'.$path)->assertOk();
        }
        $this->postJson('/api/device-tokens', ['token' => str_repeat('a', 80), 'platform' => 'android', 'provider' => 'fcm'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('device_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public static function formats(): array
    {
        return [['09171234567'], ['+639171234567'], ['639171234567'], ['(0917) 123-4567']];
    }

    #[DataProvider('formats')]
    public function test_normalization_preserves_and_authenticates_legacy_profile(string $mobile): void
    {
        $donor = $this->donor('09171234567');
        $this->verify($this->issue($mobile), $mobile)->assertOk()->assertJsonPath('user.id', $donor->id);
        $this->assertSame('09171234567', $donor->donorProfile->fresh()->mobile_number);
    }

    public static function invalidAccounts(): array
    {
        return [['admin'], ['super_admin'], ['deactivated'], ['deleted']];
    }

    #[DataProvider('invalidAccounts')]
    public function test_role_and_account_changes_invalidate_donor_codes(string $kind): void
    {
        $donor = $this->donor();
        $code = $this->issue();
        if ($kind === 'deleted') {
            $donor->delete();
        } else {
            $donor->forceFill($kind === 'deactivated' ? ['deactivated_at' => now()] : ['role' => $kind])->save();
        }
        $this->verify($code)->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => self::MOBILE])->assertStatus(202);
        app(DonorLoginService::class)->deliver($this->row()->request_id, self::MOBILE);
        Mail::assertSent(DonorLoginCodeMail::class, 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expiry_and_changed_email(): void
    {
        $donor = $this->donor();
        $code = $this->issue();
        $this->travel(600)->seconds();
        $this->verify($code)->assertUnprocessable();
        $code = $this->issue();
        $donor->forceFill(['email' => 'changed@example.test'])->save();
        $this->verify($code)->assertUnprocessable();
    }

    public function test_five_attempt_limit_commits_failures_and_rejects_correct_code(): void
    {
        $this->donor();
        $code = $this->issue();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify($code === '000000' ? '111111' : '000000')->assertUnprocessable();
            $this->assertSame($attempt, $this->row()->attempt_count);
        }
        $this->verify($code)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_resend_cooldown_replaces_old_code_and_suppresses_old_worker(): void
    {
        $this->donor();
        $code = $this->issue();
        $old = $this->row()->request_id;
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => '09171234567'])->assertStatus(429);
        $this->assertSame($old, $this->row()->request_id);
        $this->travel(60)->seconds();
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => self::MOBILE])->assertStatus(202);
        app(DonorLoginService::class)->deliver($old, self::MOBILE);
        Mail::assertSent(DonorLoginCodeMail::class, 1);
        $this->verify($code)->assertUnprocessable();
        app(DonorLoginService::class)->deliver($this->row()->request_id, self::MOBILE);
        $this->verify(Mail::sent(DonorLoginCodeMail::class)->last()->code)->assertOk();
    }

    public function test_request_and_verify_rate_limits_apply_to_unknown_numbers(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/request-login-code', ['mobile_number' => '+63917123456'.$i])->assertStatus(202);
        }
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => '+639171234569'])->assertStatus(429);
        for ($i = 0; $i < 10; $i++) {
            $this->verify('123456')->assertUnprocessable();
        }
        $this->verify('123456')->assertStatus(429);
    }

    public function test_delivery_guard_rejects_log_transport_and_replayed_worker(): void
    {
        $this->donor();
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => self::MOBILE]);
        config(['mail.default' => 'log']);
        app(DonorLoginService::class)->deliver($this->row()->request_id, self::MOBILE);
        $this->assertNull($this->row()->code_hash);
        Mail::assertNothingSent();
        $this->travel(60)->seconds();
        config(['mail.default' => 'smtp']);
        $this->issue();
        app(DonorLoginService::class)->deliver($this->row()->request_id, self::MOBILE);
        Mail::assertSent(DonorLoginCodeMail::class, 1);
    }

    public function test_registration_and_login_codes_cannot_cross_purposes(): void
    {
        $this->donor();
        $pending = $this->postJson('/api/register/request-verification', $this->registration())->assertStatus(202)->json('pending_token');
        $registration = DB::table('pending_registrations')->first();
        app(RegistrationVerificationService::class)->deliver($registration->request_id);
        $registrationCode = Mail::sent(RegistrationVerificationMail::class)->last()->code;
        $login = $this->issue();
        if ($registrationCode === $login) {
            $registrationCode = $login === '111111' ? '222222' : '111111';
            DB::table('pending_registrations')->where('request_id', $registration->request_id)->update(['code_hash' => Hash::make(hash_hmac('sha256', $registrationCode, config('app.key')))]);
        }
        $this->verify($registrationCode)->assertUnprocessable();
        $this->postJson('/api/register/verify-email', ['pending_token' => $pending, 'code' => $login])->assertUnprocessable();
        $this->verify($login)->assertOk();
        $this->postJson('/api/register/verify-email', ['pending_token' => $pending, 'code' => $registrationCode])->assertCreated();
    }

    public static function births(): array
    {
        return [['2008-10-01', true], ['2008-10-02', false], ['2000-01-01', true], ['2027-01-01', false], ['2000-02-30', false], ['invalid', false]];
    }

    #[DataProvider('births')]
    public function test_registration_minimum_age(string $birth, bool $allowed): void
    {
        $response = $this->postJson('/api/register/request-verification', $this->registration(['birth_date' => $birth]));
        if ($allowed) {
            $response->assertStatus(202);
            $this->assertNotNull(DB::table('pending_registrations')->first()->password_hash);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('birth_date');
            $this->assertDatabaseCount('pending_registrations', 0);
        }
    }

    public static function initials(): array
    {
        return [[null, null, true], [' a ', 'A', true], ['Ab', null, false], ['A.', null, false], ['1', null, false]];
    }

    #[DataProvider('initials')]
    public function test_registration_initial_and_generated_password(?string $initial, ?string $expected, bool $allowed): void
    {
        $response = $this->postJson('/api/register/request-verification', $this->registration(['middle_name' => $initial]));
        if (! $allowed) {
            $response->assertUnprocessable()->assertJsonValidationErrors('middle_name');

            return;
        }
        $pending = $response->assertStatus(202)->json('pending_token');
        app(RegistrationVerificationService::class)->deliver(DB::table('pending_registrations')->first()->request_id);
        $this->postJson('/api/register/verify-email', ['pending_token' => $pending, 'code' => Mail::sent(RegistrationVerificationMail::class)->last()->code])->assertCreated()->assertJsonPath('donor_profile.middle_name', $expected)->assertJsonMissingPath('user.password');
        $this->assertNotNull(User::first()->password);
    }

    public function test_profile_age_and_legacy_initial_compatibility(): void
    {
        $donor = $this->donor();
        $this->withToken($donor->createToken('test')->plainTextToken);
        $this->getJson('/api/donor-profile')->assertJsonPath('donor_profile.middle_name', 'Santos');
        $this->putJson('/api/donor-profile', ['birth_date' => '2008-10-02'])->assertUnprocessable();
        $this->putJson('/api/donor-profile', ['first_name' => 'Updated'])->assertJsonPath('donor_profile.middle_name', 'Santos');
        $this->putJson('/api/donor-profile', ['middle_name' => ' s ', 'birth_date' => '2008-10-01'])->assertOk()->assertJsonPath('donor_profile.middle_name', 'S');
        $this->postJson('/api/register/request-verification', $this->registration(['mobile_number' => '639171234567']))->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
    }

    public function test_current_email_confirmation_is_session_bound_one_time_and_requires_new_email_verification(): void
    {
        $donor = $this->donor();
        $token = $donor->createToken('mobile')->plainTextToken;
        $other = $donor->createToken('other-device')->plainTextToken;
        $code = $this->issue();
        $this->withToken($token)->postJson('/api/profile/change-email/confirm-login', ['mobile_number' => self::MOBILE, 'code' => $code])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->postJson('/api/profile/change-email/request', ['new_email' => 'updated@example.test'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/profile/change-email/request', ['new_email' => 'updated@example.test'])->assertStatus(202);
        $this->assertNotNull($this->row()->confirmation_used_at);
        $this->assertSame($donor->email, $donor->fresh()->email);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->postJson('/api/profile/change-email/request', ['new_email' => 'another@example.test'])->assertUnprocessable();
    }
}
