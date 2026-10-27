<?php

namespace Tests\Feature;

use App\Jobs\SendDonorLoginCode;
use App\Mail\DonorLoginCodeMail;
use App\Models\User;
use App\Services\DonorIdentity;
use App\Services\DonorLoginService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonorLoginIdentifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T00:00:00+08:00'));
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
    }

    private function donor(array $attributes = [], string $mobile = '+639171234567'): User
    {
        $user = User::factory()->create($attributes);
        $user->donorProfile()->create(['first_name' => 'Test', 'last_name' => 'Donor', 'mobile_number' => $mobile,
            'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);

        return $user;
    }

    private function row(string $identifier): object
    {
        return DB::table('donor_login_codes')->where('mobile_hash', hash('sha256', DonorIdentity::loginIdentifier($identifier)))->first();
    }

    private function deliver(string $identifier): void
    {
        app(DonorLoginService::class)->deliver($this->row($identifier)->request_id, DonorIdentity::loginIdentifier($identifier));
    }

    public static function identifiers(): array
    {
        return [[' recipient@example.test '], ['RECIPIENT@EXAMPLE.TEST'], ['09171234567'], ['+639171234567'],
            ['639171234567'], [' (0917) 123-4567 ']];
    }

    #[DataProvider('identifiers')]
    public function test_each_identifier_targets_only_stored_verified_email_and_issues_one_donor_session(string $identifier): void
    {
        $user = $this->donor(['email' => 'Recipient@example.test']);
        $response = $this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertStatus(202);
        $this->assertSame(['message', 'resend_after', 'expires_in'], array_keys($response->json()));
        $this->assertSame(DonorLoginService::NOTICE, $response->json('message'));
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertNull($this->row($identifier)->user_id);
        $normalized = DonorIdentity::loginIdentifier($identifier);
        Queue::assertPushed(SendDonorLoginCode::class, fn ($job) => $job->mobile === $normalized && $job->queue === 'donor-logins');
        $this->deliver($identifier);
        $mail = Mail::sent(DonorLoginCodeMail::class)->sole();
        $mail->assertHasTo($user->email);
        $this->assertNotSame($mail->code, $this->row($identifier)->code_hash);
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $identifier, 'code' => $mail->code])->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'donor')->assertJsonMissingPath('user.password');
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $identifier, 'code' => $mail->code])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public static function ineligibleAccounts(): array
    {
        return [['missing'], ['unverified'], ['admin'], ['super_admin'], ['deactivated'], ['deleted'], ['no_profile']];
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_unknown_and_ineligible_identifiers_have_identical_public_responses_and_no_delivery(string $kind): void
    {
        $eligible = $this->donor(['email' => 'eligible@example.test']);
        $expected = $this->postJson('/api/auth/request-login-code', ['identifier' => $eligible->email])->assertStatus(202)->json();
        $identifier = 'unmatched@example.test';
        if ($kind !== 'missing') {
            $attributes = ['email' => 'blocked@example.test'];
            if ($kind === 'unverified') {
                $attributes['email_verified_at'] = null;
            }
            if (in_array($kind, ['admin', 'super_admin'], true)) {
                $attributes['role'] = $kind;
            }
            if ($kind === 'deactivated') {
                $attributes['deactivated_at'] = now();
            }
            $blocked = $kind === 'no_profile' ? User::factory()->create($attributes) : $this->donor($attributes, '+639181234567');
            if ($kind === 'deleted') {
                $blocked->delete();
            }
            $identifier = $blocked->email;
        }
        $this->assertSame($expected, $this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertStatus(202)->json());
        $this->deliver($identifier);
        $failure = $this->postJson('/api/auth/verify-login-code', ['identifier' => $identifier, 'code' => '000000'])->assertUnprocessable()->json();
        $this->assertSame($failure, $this->postJson('/api/auth/verify-login-code', ['identifier' => $eligible->email, 'code' => '000000'])->assertUnprocessable()->json());
        $this->assertSame($this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertStatus(429)->json(),
            $this->postJson('/api/auth/request-login-code', ['identifier' => $eligible->email])->assertStatus(429)->json());
        Mail::assertNothingSent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function malformed(): array
    {
        return [[null], [''], ['   '], [[]], [1234], [true], ['not-an-email'], ['x@@example.test'], ['0917'], ['+14155551212'], [str_repeat('x', 255).'@example.test'], ["recipient@example.test\r\nBcc: other@example.test"]];
    }

    #[DataProvider('malformed')]
    public function test_malformed_input_is_rejected_before_queueing(mixed $identifier): void
    {
        $this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertUnprocessable()->assertJsonValidationErrors('identifier');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('donor_login_codes', 0);
    }

    public function test_legacy_client_stays_compatible_but_conflicting_fields_are_rejected(): void
    {
        $user = $this->donor();
        $this->postJson('/api/auth/request-login-code', ['identifier' => $user->email, 'mobile_number' => '09171234567'])->assertUnprocessable();
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => '09171234567'])->assertStatus(202);
        $this->deliver('+639171234567');
        $code = Mail::sent(DonorLoginCodeMail::class)->sole()->code;
        $this->postJson('/api/auth/verify-login-code', ['mobile_number' => '+639171234567', 'code' => $code])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_case_colliding_emails_and_ambiguous_legacy_phone_records_are_not_selected(): void
    {
        $this->donor(['email' => 'Collision@example.test']);
        $this->donor(['email' => 'collision@example.test'], '09171234567');
        foreach (['collision@example.test', '+639171234567'] as $identifier) {
            $this->postJson('/api/auth/request-login-code', ['identifier' => $identifier])->assertStatus(202);
            $this->deliver($identifier);
            $this->postJson('/api/auth/verify-login-code', ['identifier' => $identifier, 'code' => '000000'])->assertUnprocessable();
        }
        Mail::assertNothingSent();
    }

    public function test_aliases_share_delivery_cooldown_and_only_one_usable_code(): void
    {
        $user = $this->donor(['email' => 'recipient@example.test']);
        $this->postJson('/api/auth/request-login-code', ['identifier' => $user->email])->assertStatus(202);
        $this->deliver($user->email);
        $code = Mail::sent(DonorLoginCodeMail::class)->sole()->code;
        Cache::flush();
        $this->postJson('/api/auth/request-login-code', ['identifier' => '09171234567'])->assertStatus(202);
        $this->deliver('09171234567');
        Mail::assertSent(DonorLoginCodeMail::class, 1);
        $this->postJson('/api/auth/verify-login-code', ['identifier' => '09171234567', 'code' => $code])->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->postJson('/api/auth/request-login-code', ['identifier' => '09171234567'])->assertStatus(202);
        $this->deliver('09171234567');
        $this->assertNull($this->row($user->email)->code_hash);
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $user->email, 'code' => $code])->assertUnprocessable();
        $current = Mail::sent(DonorLoginCodeMail::class)->last()->code;
        $this->deliver('09171234567');
        Mail::assertSent(DonorLoginCodeMail::class, 2);
        $this->postJson('/api/auth/verify-login-code', ['identifier' => '09171234567', 'code' => $current])->assertOk();
    }

    public function test_codes_cannot_cross_accounts_or_survive_email_and_verification_changes(): void
    {
        $first = $this->donor(['email' => 'first@example.test']);
        $second = $this->donor(['email' => 'second@example.test'], '+639181234567');
        foreach ([$first, $second] as $user) {
            $this->postJson('/api/auth/request-login-code', ['identifier' => $user->email])->assertStatus(202);
            $this->deliver($user->email);
        }
        DB::table('donor_login_codes')->where('mobile_hash', $this->row($first->email)->mobile_hash)->update(['code_hash' => Hash::make(hash_hmac('sha256', '123456', config('app.key')))]);
        DB::table('donor_login_codes')->where('mobile_hash', $this->row($second->email)->mobile_hash)->update(['code_hash' => Hash::make(hash_hmac('sha256', '654321', config('app.key')))]);
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $second->email, 'code' => '123456'])->assertUnprocessable();
        $first->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $first->email, 'code' => '123456'])->assertUnprocessable();
        $second->forceFill(['email' => 'changed@example.test'])->save();
        $this->postJson('/api/auth/verify-login-code', ['identifier' => '+639181234567', 'code' => '654321'])->assertUnprocessable();
        $this->postJson('/api/auth/verify-login-code', ['identifier' => 'changed@example.test', 'code' => '654321'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_codes_keep_expiry_attempt_limits_and_ip_identifier_throttles(): void
    {
        $user = $this->donor(['email' => 'recipient@example.test']);
        $this->postJson('/api/auth/request-login-code', ['identifier' => $user->email])->assertStatus(202);
        $this->deliver($user->email);
        $code = Mail::sent(DonorLoginCodeMail::class)->sole()->code;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/verify-login-code', ['identifier' => ' RECIPIENT@EXAMPLE.TEST ', 'code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertSame(5, $this->row($user->email)->attempt_count);
        for ($attempt = 6; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/verify-login-code', ['identifier' => ' RECIPIENT@EXAMPLE.TEST ', 'code' => $code])->assertUnprocessable();
        }
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $user->email, 'code' => $code])->assertStatus(429);
        $this->travel(600)->seconds();
        $this->postJson('/api/auth/verify-login-code', ['identifier' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_login_does_not_bypass_current_email_confirmation_or_authentication(): void
    {
        $user = $this->donor(['email' => 'recipient@example.test']);
        $this->postJson('/api/profile/change-email/confirm-login', ['identifier' => $user->email, 'code' => '000000'])->assertUnauthorized();
        $this->postJson('/api/profile/change-email/request', ['new_email' => 'new@example.test'])->assertUnauthorized();
        $this->postJson('/api/auth/request-login-code', ['identifier' => $user->email])->assertStatus(202);
        $this->deliver($user->email);
        $code = Mail::sent(DonorLoginCodeMail::class)->sole()->code;
        $token = $this->postJson('/api/auth/verify-login-code', ['identifier' => $user->email, 'code' => $code])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/profile/change-email/request', ['new_email' => 'new@example.test'])->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->postJson('/api/auth/request-login-code', ['mobile_number' => '09171234567'])->assertStatus(202);
        $this->deliver('09171234567');
        $this->postJson('/api/profile/change-email/confirm-login', ['mobile_number' => '09171234567', 'code' => Mail::sent(DonorLoginCodeMail::class)->last()->code])->assertOk();
        $this->postJson('/api/profile/change-email/request', ['new_email' => 'new@example.test'])->assertStatus(202);
        $this->assertSame('recipient@example.test', $user->fresh()->email);
        $this->assertNotNull($this->row('09171234567')->confirmation_used_at);

    }
}
