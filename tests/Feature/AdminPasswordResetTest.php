<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        Queue::fake();
        Mail::fake();
        $this->freezeTime();
    }

    private function row(string $email): ?object
    {
        return DB::table('password_reset_codes')->where('email_hash', hash('sha256', 'admin-password-reset:'.strtolower($email)))->first();
    }

    private function code(User $user): string
    {
        $this->postJson('/api/admin/forgot-password/request', ['email' => ' '.strtoupper($user->email).' '])->assertAccepted();
        Queue::pushed(SendPasswordResetCode::class)->last()->handle(app(PasswordResetService::class));

        return Mail::sent(PasswordResetCodeMail::class)->last()->code;
    }

    private function authorization(User $user): string
    {
        return $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $this->code($user)])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('reset_token');
    }

    private function body(User $user, string $token): array
    {
        return ['email' => $user->email, 'reset_token' => $token, 'password' => 'NewStaffPass123!', 'password_confirmation' => 'NewStaffPass123!'];
    }

    public static function staff(): array
    {
        return [['admin', false], ['super_admin', false], ['admin', true], ['super_admin', true]];
    }

    #[DataProvider('staff')]
    public function test_staff_can_reset_without_changing_roles_or_bypassing_initial_password_gate(string $role, bool $initial): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $initial, 'password' => 'OldStaffPass123!']);
        $other = User::factory()->create();
        $other->createToken('unrelated-session');
        $user->createToken('old-admin-session');
        DB::table('sessions')->insert(['id' => 'old-staff-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $token = $this->authorization($user);
        Queue::assertPushed(SendPasswordResetCode::class, fn ($job) => $job->staffOnly && $job->queue === 'password-resets');
        $this->assertSame(hash('sha256', $token), $this->row($user->email)->reset_token_hash);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $this->row($user->email)->reset_token_expires_at);
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $token))->assertOk()
            ->assertExactJson(['message' => 'Your password has been updated successfully.']);
        $this->assertTrue(Hash::check('NewStaffPass123!', $user->fresh()->password));
        $this->assertSame($role, $user->fresh()->role->value);
        $this->assertSame($initial, $user->fresh()->must_change_password);
        $this->assertNull($user->fresh()->deactivated_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-staff-session']);
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $token))->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'OldStaffPass123!'])->assertUnauthorized();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'NewStaffPass123!'])->assertOk()
            ->assertJsonPath('user.must_change_password', $initial)->assertJsonPath('user.role', $role);
    }

    public function test_request_is_neutral_and_worker_rejects_nonstaff_unknown_inactive_and_deleted_accounts(): void
    {
        $donor = User::factory()->create();
        $inactive = User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);
        $deleted = User::factory()->create(['role' => 'super_admin']);
        $deleted->delete();
        foreach ([$donor->email, $inactive->email, $deleted->email, 'unknown@example.test'] as $email) {
            $this->postJson('/api/admin/forgot-password/request', ['email' => $email])->assertAccepted()
                ->assertExactJson(['message' => PasswordResetService::NOTICE, 'resend_after' => 60]);
            app(PasswordResetService::class)->deliver($email, $this->row($email)->request_id, true);
            $this->assertNotNull($this->row($email)->used_at);
            $this->postJson('/api/admin/forgot-password/verify', ['email' => $email, 'code' => '123456'])->assertUnprocessable();
        }
        Mail::assertNothingSent();
        Queue::assertPushed(SendPasswordResetCode::class, 4);
        $this->assertSame(3, User::withTrashed()->count());
    }

    public function test_code_is_hashed_bounded_short_lived_and_single_use(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $code = $this->code($user);
        $row = $this->row($user->email);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check(hash_hmac('sha256', $code, config('app.key')), $row->code_hash));
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $row->expires_at);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $wrong])->assertUnprocessable();
        }
        $this->assertSame(5, $this->row($user->email)->attempt_count);
        $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->travel(61)->seconds();
        $code = $this->code($user);
        $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
    }

    public function test_expired_code_and_reset_authorization_fail_and_resend_replaces_old_authorization(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $code = $this->code($user);
        $this->travel(15)->minutes();
        $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $token = $this->authorization($user);
        $this->travel(10)->minutes();
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $token))->assertUnprocessable();
        $token = $this->authorization($user);
        $this->travel(61)->seconds();
        $this->code($user);
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $token))->assertUnprocessable();
    }

    public static function eligibilityChanges(): array
    {
        return [['deactivate', 'verify'], ['donor', 'verify'], ['delete', 'verify'], ['deactivate', 'reset'], ['donor', 'reset'], ['delete', 'reset']];
    }

    #[DataProvider('eligibilityChanges')]
    public function test_changed_eligibility_cannot_use_issued_credentials(string $change, string $step): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $code = $this->code($user);
        $token = $step === 'reset' ? $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertOk()->json('reset_token') : null;
        if ($change === 'deactivate') {
            $user->forceFill(['deactivated_at' => now()])->save();
        } elseif ($change === 'donor') {
            $user->forceFill(['role' => 'donor'])->save();
        } else {
            $user->delete();
        }
        $this->postJson('/api/admin/forgot-password/'.$step, $step === 'reset' ? $this->body($user, $token) : ['email' => $user->email, 'code' => $code])->assertUnprocessable();
    }

    public function test_staff_and_generic_recovery_credentials_are_isolated_and_success_invalidates_both(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $service = app(PasswordResetService::class);
        $service->requestCode($user->email);
        $generic = DB::table('password_reset_codes')->where('email_hash', hash('sha256', $user->email))->first();
        $service->deliver($user->email, $generic->request_id);
        Mail::assertNothingSent();
        $this->postJson('/api/forgot-password/verify', ['email' => $user->email, 'code' => '123456'])->assertUnprocessable();
        $genericToken = str_repeat('a', 64);
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $genericToken))->assertUnprocessable();
        $token = $this->authorization($user);
        $this->postJson('/api/forgot-password/reset', $this->body($user, $token))->assertUnprocessable();
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, $token))->assertOk();
        $this->postJson('/api/forgot-password/reset', $this->body($user, $genericToken))->assertUnprocessable();
        $this->assertSame(2, DB::table('password_reset_codes')->whereNotNull('used_at')->count());
    }

    public function test_strong_password_confirmation_and_allowlisted_inputs_are_enforced(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/admin/forgot-password/request', ['email' => $user->email, 'role' => 'super_admin'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $token = $this->authorization($user);
        foreach (['short', 'alllowercase1!', 'ALLUPPERCASE1!', 'NoNumbersHere!', 'NoSymbols1234', str_repeat('A', 73).'1!'] as $password) {
            $this->postJson('/api/admin/forgot-password/reset', [...$this->body($user, $token), 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable();
        }
        $this->postJson('/api/admin/forgot-password/reset', [...$this->body($user, $token), 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->assertNull($this->row($user->email)->used_at);
    }

    public function test_shared_ip_limits_and_per_email_resend_cooldown_are_enforced(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/forgot-password/request', ['email' => $user->email])->assertAccepted();
        }
        Queue::assertPushed(SendPasswordResetCode::class, 1);
        $this->postJson('/api/admin/forgot-password/request', ['email' => $user->email])->assertTooManyRequests();
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/admin/forgot-password/verify', ['email' => $user->email, 'code' => '000000'])->assertTooManyRequests();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/admin/forgot-password/reset', $this->body($user, str_repeat('a', 64)))->assertUnprocessable();
        }
        $this->postJson('/api/admin/forgot-password/reset', $this->body($user, str_repeat('a', 64)))->assertTooManyRequests();
    }

    public function test_old_queued_jobs_without_staff_flag_still_deliver_generic_recovery(): void
    {
        $donor = User::factory()->create();
        app(PasswordResetService::class)->requestCode($donor->email);
        $job = Queue::pushed(SendPasswordResetCode::class)->last();
        unset($job->staffOnly);
        $restored = unserialize(serialize($job));
        $this->assertFalse($restored->staffOnly);
        $restored->handle(app(PasswordResetService::class));
        Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo($donor->email));
    }
}
