<?php

namespace Tests\Feature;

use App\Jobs\SendEmailChangeCode;
use App\Mail\EmailChangeCodeMail;
use App\Models\User;
use App\Services\AccountSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    private User $donor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        Mail::fake();
        $this->freezeTime();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $this->donor = User::factory()->create(['email' => 'old@example.test', 'password' => Hash::make('password123')]);
        $this->token = $this->donor->createToken('current')->plainTextToken;
    }

    private function submitSetting(string $route, array $data): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson('/api/profile/'.$route, $data);
    }

    private function row(): object
    {
        return DB::table('email_changes')->where('user_id', $this->donor->id)->first();
    }

    private function start(): array
    {
        $r = $this->submitSetting('change-email/request', ['current_password' => 'password123', 'new_email' => 'NEW@example.test'])->assertStatus(202);
        app(AccountSettingsService::class)->deliver($this->row()->request_id);

        return [$r->json('pending_token'), Mail::sent(EmailChangeCodeMail::class)->last()->code];
    }

    private function verify(string $token, string $code): TestResponse
    {
        return $this->submitSetting('change-email/verify', ['pending_token' => $token, 'code' => $code]);
    }

    public function test_guests_cannot_use_any_account_setting_endpoint(): void
    {
        foreach (['change-email/request', 'change-email/resend', 'change-email/verify', 'change-password'] as $route) {
            $this->postJson('/api/profile/'.$route, [])->assertUnauthorized();
        }
        Queue::assertNothingPushed();
    }

    public function test_email_request_requires_password_valid_unique_different_email_and_strict_fields(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        foreach ([
            [[], 'current_password'],
            [['current_password' => 'wrong', 'new_email' => 'new@example.test'], 'current_password'],
            [['current_password' => 'password123', 'new_email' => 'invalid'], 'new_email'],
            [['current_password' => 'password123', 'new_email' => 'OLD@example.test'], 'new_email'],
            [['current_password' => 'password123', 'new_email' => 'taken@example.test'], 'new_email'],
            [['current_password' => 'password123', 'new_email' => 'new@example.test', 'user_id' => 9], 'user_id'],
        ] as [$data,$field]) {
            $this->submitSetting('change-email/request', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('email_changes', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_code_storage_delivery_and_success_preserve_profile_and_current_login(): void
    {
        [$token,$code] = $this->start();
        $row = $this->row();
        $this->assertNotSame($token, $row->pending_token_hash);
        $this->assertStringNotContainsString('new@example.test', $row->new_email);
        $this->assertTrue(Hash::check(hash_hmac('sha256', $code, config('app.key')), $row->code_hash));
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $row->expires_at);
        $this->assertSame('old@example.test', $this->donor->fresh()->email);
        Queue::assertPushed(SendEmailChangeCode::class, fn ($job) => $job->queue === 'email-changes' && $job->requestId === $row->request_id);
        Mail::assertSent(EmailChangeCodeMail::class, fn ($mail) => $mail->hasTo('new@example.test') && ! $mail->hasTo('old@example.test'));
        $response = $this->verify($token, $code)->assertOk()->assertJsonPath('user.email', 'new@example.test')->assertJsonMissingPath('user.password');
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertNotNull($this->donor->fresh()->email_verified_at);
        $this->assertNull($this->row()->code_hash);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/user')->assertOk()->assertJsonPath('email', 'new@example.test');
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_resend_waits_and_invalidates_old_code_and_old_jobs(): void
    {
        [$token,$code] = $this->start();
        $old = $this->row()->request_id;
        $this->submitSetting('change-email/resend', ['pending_token' => $token])->assertStatus(429);
        $this->travel(60)->seconds();
        $this->submitSetting('change-email/resend', ['pending_token' => $token])->assertStatus(202);
        $this->assertNull($this->row()->code_hash);
        $this->verify($token, $code)->assertUnprocessable();
        app(AccountSettingsService::class)->deliver($old);
        Mail::assertSentCount(1);
        $id = $this->row()->request_id;
        app(AccountSettingsService::class)->deliver($id);
        app(AccountSettingsService::class)->deliver($id);
        Mail::assertSentCount(2);
        $this->verify($token, Mail::sent(EmailChangeCodeMail::class)->last()->code)->assertOk();
    }

    public function test_five_wrong_attempts_commit_and_lock_code(): void
    {
        [$token,$code] = $this->start();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 5; $i++) {
            $this->verify($token, $wrong)->assertUnprocessable();
            $this->assertSame($i, $this->row()->attempt_count);
        }
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertSame('old@example.test', $this->donor->fresh()->email);
    }

    public function test_code_expires_at_exact_fifteen_minutes(): void
    {
        [$token,$code] = $this->start();
        $this->travel(15)->minutes();
        $this->verify($token, $code)->assertUnprocessable();
        $this->assertSame('old@example.test', $this->donor->fresh()->email);
    }

    public function test_pending_token_cannot_be_used_by_another_donor_or_login(): void
    {
        [$pending,$code] = $this->start();
        $original = $this->token;
        $this->token = User::factory()->create()->createToken('other')->plainTextToken;
        $this->verify($pending, $code)->assertUnprocessable();
        $this->submitSetting('change-email/resend', ['pending_token' => $pending])->assertUnprocessable();
        $this->token = $this->donor->createToken('second-login')->plainTextToken;
        $this->verify($pending, $code)->assertUnprocessable();
        $this->token = $original;
        $this->verify($pending, $code)->assertOk();
    }

    public function test_uniqueness_is_rechecked_at_verification(): void
    {
        [$token,$code] = $this->start();
        User::factory()->create(['email' => 'new@example.test']);
        $this->verify($token, $code)->assertUnprocessable()->assertJsonValidationErrors('new_email');
        $this->assertSame('old@example.test', $this->donor->fresh()->email);
        $this->assertNull($this->row()->used_at);
    }

    public function test_replacing_request_invalidates_previous_pending_token(): void
    {
        [$old,$code] = $this->start();
        $this->travel(60)->seconds();
        $this->submitSetting('change-email/request', ['current_password' => 'password123', 'new_email' => 'different@example.test'])->assertStatus(202);
        $this->verify($old, $code)->assertUnprocessable();
        $this->assertDatabaseCount('email_changes', 1);
    }

    public function test_delivery_failure_is_safe_and_does_not_make_code_usable(): void
    {
        $this->submitSetting('change-email/request', ['current_password' => 'password123', 'new_email' => 'new@example.test'])->assertStatus(202);
        config(['mail.default' => 'log']);
        app(AccountSettingsService::class)->deliver($this->row()->request_id);
        Mail::assertNothingSent();
        $this->assertNull($this->row()->code_hash);
    }

    public function test_password_validation_and_wrong_current_password_leave_hash_unchanged(): void
    {
        $before = $this->donor->password;
        foreach ([
            [['current_password' => 'wrong', 'password' => 'newPassword123', 'password_confirmation' => 'newPassword123'], 'current_password'],
            [['current_password' => 'password123', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
            [['current_password' => 'password123', 'password' => 'newPassword123', 'password_confirmation' => 'different'], 'password'],
            [['current_password' => 'password123', 'password' => 'password123', 'password_confirmation' => 'password123'], 'password'],
            [['current_password' => 'password123', 'password' => str_repeat('x', 73), 'password_confirmation' => str_repeat('x', 73)], 'password'],
        ] as [$data,$field]) {
            $this->submitSetting('change-password', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->donor->fresh()->password);
    }

    public function test_password_change_revokes_other_logins_and_pending_email_but_preserves_current(): void
    {
        [$pending,$code] = $this->start();
        $other = $this->donor->createToken('other')->plainTextToken;
        $this->submitSetting('change-password', ['current_password' => 'password123', 'password' => 'newPassword123', 'password_confirmation' => 'newPassword123'])
            ->assertOk()->assertJsonPath('message', 'Password updated successfully.');
        $this->assertTrue(Hash::check('newPassword123', $this->donor->fresh()->password));
        $this->assertFalse(Hash::check('password123', $this->donor->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->verify($pending, $code)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'password123'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'newPassword123'])->assertOk();
    }

    public function test_password_change_cleans_other_devices_and_existing_reset_authorization(): void
    {
        $currentId = (int) explode('|', $this->token)[0];
        $other = $this->donor->createToken('other-device');
        foreach ([$currentId, $other->accessToken->id] as $id) {
            DB::table('device_tokens')->insert([
                'user_id' => $this->donor->id, 'personal_access_token_id' => $id,
                'token' => 'test-device-'.$id, 'token_hash' => hash('sha256', 'test-device-'.$id),
                'platform' => 'android', 'provider' => 'fcm', 'last_seen_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('sessions')->insert(['id' => 'test-browser', 'user_id' => $this->donor->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_codes')->insert([
            'email_hash' => hash('sha256', 'old@example.test'), 'request_id' => (string) Str::uuid(),
            'user_id' => $this->donor->id, 'code_hash' => 'old-hash', 'expires_at' => now()->addMinutes(15),
            'reset_token_hash' => 'old-reset-hash', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->submitSetting('change-password', ['current_password' => 'password123', 'password' => 'newPassword123', 'password_confirmation' => 'newPassword123'])->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['personal_access_token_id' => $currentId]);
        $this->assertDatabaseCount('sessions', 0);
        $reset = DB::table('password_reset_codes')->first();
        $this->assertNull($reset->code_hash);
        $this->assertNull($reset->reset_token_hash);
        $this->assertNotNull($reset->used_at);
    }

    public function test_delayed_job_and_day_old_pending_request_cannot_extend_authorization(): void
    {
        $response = $this->submitSetting('change-email/request', ['current_password' => 'password123', 'new_email' => 'new@example.test'])->assertStatus(202);
        $this->travel(15)->minutes();
        app(AccountSettingsService::class)->deliver($this->row()->request_id);
        Mail::assertNothingSent();
        $this->travel(24)->hours();
        $this->submitSetting('change-email/resend', ['pending_token' => $response->json('pending_token')])->assertUnprocessable();
    }

    public function test_email_change_rolls_back_on_failure_and_does_not_leak_internal_details(): void
    {
        [$token, $code] = $this->start();
        User::updating(function (): void {
            throw new \RuntimeException('private internal exception');
        });
        try {
            $response = $this->verify($token, $code)->assertStatus(500);
            $this->assertStringNotContainsString('private internal exception', $response->getContent());
            $this->assertSame('old@example.test', $this->donor->fresh()->email);
            $this->assertNull($this->row()->used_at);
        } finally {
            User::flushEventListeners();
        }
    }
}
