<?php

namespace Tests\Feature;

use App\Jobs\SendDonorLoginCode;
use App\Jobs\SendEmailChangeCode;
use App\Jobs\SendPasswordResetCode;
use App\Jobs\SendRegistrationVerificationCode;
use App\Models\User;
use App\Services\AccountSettingsService;
use App\Services\DonorLoginService;
use App\Services\PasswordResetService;
use App\Services\RegistrationVerificationService;
use App\Services\TemporaryAuthRetention;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TemporaryAuthRetentionTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->freezeTime();
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['email' => 'retention@example.test']);
        $this->owner->donorProfile()->create(['first_name' => 'Synthetic', 'last_name' => 'Donor', 'mobile_number' => '+639171234567', 'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);
    }

    private function fixture(string $table, array $changes = []): object
    {
        $old = now()->subDays(3);
        $row = $table === 'password_reset_tokens' ? ['email' => 'retention@example.test', 'token' => 'sensitive-canary', 'created_at' => $old] : [
            TemporaryAuthRetention::TABLES[$table] => $table === 'email_changes' ? $this->owner->id : hash('sha256', 'retention@example.test'),
            'request_id' => (string) Str::uuid(), 'expires_at' => now()->subHours(24), 'code_hash' => 'sensitive-canary',
            'created_at' => $old, 'updated_at' => $old,
        ];
        if (in_array($table, ['pending_registrations', 'donor_login_codes', 'email_changes'], true)) {
            $row['resend_at'] = $old;
        }
        if ($table === 'pending_registrations') {
            $row += ['pending_token_hash' => hash('sha256', 'pending'), 'payload' => Crypt::encryptString(json_encode(['privacy_version' => '1.0', 'private' => 'sensitive-canary'])), 'password_hash' => 'sensitive-canary'];
        }
        if ($table === 'email_changes') {
            $row += ['personal_access_token_id' => $this->owner->createToken('mobile')->accessToken->id,
                'pending_token_hash' => hash('sha256', 'change'), 'new_email' => Crypt::encryptString('new@example.test'),
                'original_email_hash' => hash('sha256', $this->owner->email), 'credential_hash' => hash('sha256', $this->owner->password)];
        }
        DB::table($table)->insert([...$row, ...$changes]);

        return DB::table($table)->first();
    }

    public static function cutoffs(): array
    {
        $cases = [];
        foreach (array_keys(TemporaryAuthRetention::TABLES) as $table) {
            foreach ([-1, 0, 1] as $seconds) {
                $cases[$table.' offset '.$seconds] = [$table, $seconds];
            }
        }

        return $cases;
    }

    #[DataProvider('cutoffs')]
    public function test_grace_boundary_dry_run_and_idempotent_cleanup(string $table, int $seconds): void
    {
        $changes = $table === 'password_reset_tokens'
            ? ['created_at' => now()->subHours(25)->addSeconds($seconds)]
            : ['expires_at' => now()->subHours(24)->addSeconds($seconds)];
        $this->fixture($table, $changes);
        $this->artisan('auth:prune-temporary', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount($table, 1);
        $result = app(TemporaryAuthRetention::class)->run();
        $expected = $seconds <= 0 ? 1 : 0;
        $this->assertSame($expected, $result['tables'][$table]['deleted']);
        $this->assertDatabaseCount($table, 1 - $expected);
        $this->assertSame(0, app(TemporaryAuthRetention::class)->run()['tables'][$table]['deleted']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function preservedWindows(): array
    {
        return [['pending_registrations', 'expires_at'], ['pending_registrations', 'resend_at'], ['pending_registrations', 'updated_at'],
            ['donor_login_codes', 'expires_at'], ['donor_login_codes', 'resend_at'], ['donor_login_codes', 'delivery_claimed_at'],
            ['donor_login_codes', 'confirmed_at'], ['password_reset_codes', 'reset_token_expires_at'],
            ['email_changes', 'resend_at'], ['email_changes', 'delivery_claimed_at']];
    }

    #[DataProvider('preservedWindows')]
    public function test_active_recent_and_secondary_authorization_windows_are_preserved(string $table, string $field): void
    {
        $this->fixture($table, [$field => now()->addMinute()]);
        $this->artisan('auth:prune-temporary')->assertSuccessful();
        $this->assertDatabaseCount($table, 1);
    }

    public static function invalidDates(): array
    {
        return [['expires_at', ''], ['expires_at', 'not-a-date'], ['expires_at', '2026-02-30 00:00:00'],
            ['created_at', null], ['updated_at', 'bad'], ['resend_at', 'bad']];
    }

    #[DataProvider('invalidDates')]
    public function test_missing_or_invalid_timestamps_fail_closed(string $field, ?string $value): void
    {
        $this->fixture('pending_registrations', [$field => $value]);
        $this->artisan('auth:prune-temporary')->assertSuccessful();
        $this->assertDatabaseCount('pending_registrations', 1);
    }

    public static function consumption(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('consumption')]
    public function test_consumed_and_old_consent_rows_expire_but_delayed_jobs_cannot_recreate_or_email_them(bool $used): void
    {
        $rows = [];
        foreach (['pending_registrations', 'donor_login_codes', 'password_reset_codes', 'email_changes'] as $table) {
            $rows[$table] = $this->fixture($table, ['used_at' => $used ? now()->subDays(2) : null]);
        }
        $this->artisan('auth:prune-temporary')->assertSuccessful();
        (new SendRegistrationVerificationCode($rows['pending_registrations']->request_id))->handle(app(RegistrationVerificationService::class));
        (new SendDonorLoginCode($rows['donor_login_codes']->request_id, $this->owner->email))->handle(app(DonorLoginService::class));
        foreach ([false, true] as $staffOnly) {
            (new SendPasswordResetCode($this->owner->email, $rows['password_reset_codes']->request_id, $staffOnly))->handle(app(PasswordResetService::class));
        }
        (new SendEmailChangeCode($rows['email_changes']->request_id))->handle(app(AccountSettingsService::class));
        foreach (array_keys($rows) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('user_consents', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_replacement_between_scan_and_lock_is_preserved_with_its_cooldown(): void
    {
        $this->fixture('donor_login_codes');
        $retention = new class extends TemporaryAuthRetention
        {
            protected function pruneCandidate(string $table, string $key, object $candidate, CarbonInterface $cutoff, bool $dryRun): bool
            {
                if ($table === 'donor_login_codes') {
                    DB::table($table)->where($key, $candidate->{$key})->update(['request_id' => (string) Str::uuid(),
                        'expires_at' => now()->addMinutes(10), 'resend_at' => now()->addMinute(), 'updated_at' => now()]);
                }

                return parent::pruneCandidate($table, $key, $candidate, $cutoff, $dryRun);
            }
        };
        $this->assertSame(0, $retention->run()['tables']['donor_login_codes']['deleted']);
        $this->assertDatabaseCount('donor_login_codes', 1);
        Cache::forget('donor-login:'.hash('sha256', $this->owner->email));
        $this->postJson('/api/auth/request-login-code', ['identifier' => $this->owner->email])->assertTooManyRequests();
        Queue::assertNothingPushed();
    }

    public function test_primary_key_budget_and_encrypted_cursor_allow_bounded_continuation(): void
    {
        $row = (array) $this->fixture('pending_registrations');
        DB::table('pending_registrations')->delete();
        $rows = [];
        for ($index = 0; $index < 1005; $index++) {
            $rows[] = [...$row, 'email_hash' => str_pad((string) $index, 64, '0', STR_PAD_LEFT),
                'pending_token_hash' => hash('sha256', 'token'.$index), 'request_id' => (string) Str::uuid()];
        }
        foreach (array_chunk($rows, 100) as $page) {
            DB::table('pending_registrations')->insert($page);
        }
        $result = app(TemporaryAuthRetention::class)->run();
        $this->assertSame(1000, $result['tables']['pending_registrations']['scanned']);
        $this->assertSame(1000, $result['tables']['pending_registrations']['deleted']);
        $hint = Cache::get('lifeflow:auth-retention-cursor:pending_registrations');
        $this->assertNotSame(str_pad('999', 64, '0', STR_PAD_LEFT), $hint);
        $this->assertSame(str_pad('999', 64, '0', STR_PAD_LEFT), Crypt::decryptString($hint));
        $this->assertSame(5, app(TemporaryAuthRetention::class)->run()['tables']['pending_registrations']['deleted']);
        $this->assertNull(Cache::get('lifeflow:auth-retention-cursor:pending_registrations'));
    }

    public function test_failures_roll_back_the_row_and_emit_only_safe_operational_text(): void
    {
        $this->fixture('pending_registrations');
        DB::unprepared("CREATE TRIGGER retention_failure BEFORE DELETE ON pending_registrations BEGIN SELECT RAISE(ABORT, 'sensitive-canary'); END");
        Log::spy();
        $this->artisan('auth:prune-temporary')->expectsOutputToContain('Temporary authentication retention failed.')->assertFailed();
        $this->assertDatabaseCount('pending_registrations', 1);
        Log::shouldHaveReceived('error')->once()->with('Temporary authentication retention failed; earlier row transactions may have committed.');
        Log::shouldNotHaveReceived('info');
    }

    public function test_shared_lock_prevents_overlapping_scans_without_deleting_anything(): void
    {
        $this->fixture('pending_registrations');
        $lock = Cache::lock('lifeflow:temporary-auth-retention', 120);
        $this->assertTrue($lock->get());
        try {
            $this->assertTrue(app(TemporaryAuthRetention::class)->run()['busy']);
            $this->assertDatabaseCount('pending_registrations', 1);
        } finally {
            $lock->release();
        }
    }

    public function test_old_delivery_and_failure_callbacks_cannot_invalidate_a_replacement_after_pruning(): void
    {
        $old = $this->fixture('donor_login_codes');
        app(TemporaryAuthRetention::class)->run();
        $new = $this->fixture('donor_login_codes', ['expires_at' => now()->addMinutes(10), 'resend_at' => now()->addMinute(), 'updated_at' => now()]);
        $job = new SendDonorLoginCode($old->request_id, $this->owner->email);
        $job->handle(app(DonorLoginService::class));
        $job->failed(null);
        $this->assertDatabaseHas('donor_login_codes', ['request_id' => $new->request_id, 'code_hash' => 'sensitive-canary']);
        $this->assertDatabaseCount('donor_login_codes', 1);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_two_real_cleanup_workers_do_not_double_delete_or_touch_accounts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lifeflow-r4-auth-');
        $this->assertIsString($path);
        $workers = [];
        try {
            $script = "require 'vendor/autoload.php'; \$app=require 'bootstrap/app.php'; \$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(['database.default'=>'sqlite','database.connections.sqlite.database'=>\$argv[1],'database.connections.sqlite.busy_timeout'=>5000,'cache.default'=>'array']); \\Illuminate\\Support\\Facades\\DB::purge('sqlite'); \\Illuminate\\Support\\Carbon::setTestNow(\$argv[2]); try { echo json_encode(app(\\App\\Services\\TemporaryAuthRetention::class)->run()); } catch (\\Throwable) { echo '{\"error\":true}'; }";
            // Copy only this synthetic in-memory test database into a temporary SQLite file.
            DB::connection()->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $path.'.copy')."'");
            unlink($path);
            rename($path.'.copy', $path);
            // Populate after moving this fixture connection to the disposable copy.
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
            $this->fixture('pending_registrations');
            foreach ([1, 2] as $unused) {
                $worker = new Process([PHP_BINARY, '-r', $script, $path, now()->toDateTimeString()], base_path());
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deleted = 0;
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode());
                $result = json_decode($worker->getOutput(), true);
                $this->assertArrayNotHasKey('error', $result);
                $deleted += $result['tables']['pending_registrations']['deleted'];
            }
            $this->assertSame(1, $deleted);
            $this->assertDatabaseCount('pending_registrations', 0);
            $this->assertDatabaseCount('users', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach ([$path, $path.'.copy', $path.'-wal', $path.'-shm'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }

    }
}
