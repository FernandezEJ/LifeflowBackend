<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapFirstSuperAdmin;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\FirstSuperAdminBootstrap;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class FirstSuperAdminBootstrapTest extends TestCase
{
    private const PASSWORD = 'Synthetic-Only-Password-123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->freezeTime();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app?->instance('env', 'testing');
        parent::tearDown();
    }

    private function fixture(): FirstSuperAdminBootstrap
    {
        // Only the MySQL lock boundary is replaced; transaction/account/audit logic uses isolated SQLite.
        return new class extends FirstSuperAdminBootstrap
        {
            public bool $busy = false;

            public int $checks = 0;

            public int $failCheck = 0;

            public int $releases = 0;

            protected function acquire(Connection $connection, string $lock): bool
            {
                return ! $this->busy;
            }

            protected function assertOwned(Connection $connection, string $lock): void
            {
                if (++$this->checks === $this->failCheck) {
                    throw new RuntimeException('Synthetic lock loss.');
                }
            }

            protected function release(Connection $connection, string $lock): void
            {
                $this->releases++;
            }
        };
    }

    private function create(FirstSuperAdminBootstrap $service, string $email = 'first@example.test', string $password = self::PASSWORD): void
    {
        $this->app->instance('env', 'production');
        $service->create('  Synthetic Staff  ', $email, $password);
    }

    public function test_atomic_bootstrap_supports_existing_staff_auth_password_gate_and_twelve_hour_session(): void
    {
        $this->create($this->fixture(), ' FIRST@EXAMPLE.TEST ');
        $user = User::firstOrFail();
        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertSame('Synthetic Staff', $user->name);
        $this->assertSame('first@example.test', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('donor_profiles', 0);
        $this->assertDatabaseCount('user_consents', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => FirstSuperAdminBootstrap::AUDIT_ACTION,
            'actor_user_id' => $user->id, 'target_id' => $user->id, 'details' => null]);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $token = $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()->json('token');
        $this->assertSame(now()->addHours(12)->toDateTimeString(), $user->tokens()->firstOrFail()->expires_at->toDateTimeString());
        $this->withToken($token)->getJson('/api/admin/dashboard')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/change-initial-password', ['current_password' => self::PASSWORD,
            'password' => 'New-Synthetic-Password-123!', 'password_confirmation' => 'New-Synthetic-Password-123!'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public static function nonproduction(): array
    {
        return [['local'], ['testing'], ['staging'], ['']];
    }

    #[DataProvider('nonproduction')]
    public function test_service_rejects_nonproduction_before_lock_or_writes(string $environment): void
    {
        $this->app->instance('env', $environment);
        try {
            $this->fixture()->create('Synthetic', 'first@example.test', self::PASSWORD);
            $this->fail('Production-only bootstrap was allowed.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public static function staffHistory(): array
    {
        $cases = [];
        foreach (['admin', 'super_admin'] as $role) {
            foreach (['active', 'deactivated', 'deleted'] as $state) {
                $cases[] = [$role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('staffHistory')]
    public function test_any_existing_staff_history_is_untouched_and_blocks_bootstrap(string $role, string $state): void
    {
        $user = User::factory()->create(['role' => $role]);
        if ($state === 'deactivated') {
            $user->forceFill(['deactivated_at' => now()])->save();
        }
        if ($state === 'deleted') {
            $user->delete();
        }
        $before = DB::table('users')->get()->toJson();
        try {
            $this->create($this->fixture());
            $this->fail('Existing staff must prevent bootstrap.');
        } catch (RuntimeException) {
            $this->assertSame($before, DB::table('users')->get()->toJson());
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public static function donorStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('donorStates')]
    public function test_email_collision_never_promotes_or_reactivates_a_donor(bool $deleted): void
    {
        $user = User::factory()->create(['email' => 'FIRST@EXAMPLE.TEST']);
        if ($deleted) {
            $user->delete();
        }
        $before = DB::table('users')->get()->toJson();
        try {
            $this->create($this->fixture());
            $this->fail('Colliding donor must not be changed.');
        } catch (RuntimeException) {
            $this->assertSame($before, DB::table('users')->get()->toJson());
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_consumed_marker_blocks_bootstrap_even_without_remaining_staff(): void
    {
        AuditLog::create(['actor_user_id' => null, 'actor_role' => UserRole::SuperAdmin, 'action' => FirstSuperAdminBootstrap::AUDIT_ACTION,
            'module' => 'admin_accounts', 'target_type' => 'user', 'target_id' => 999]);
        try {
            $this->create($this->fixture());
            $this->fail('Consumed bootstrap marker must prevent reuse.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 1);
        }
    }

    public function test_repeated_invocation_never_resets_a_created_account_or_creates_a_second_one(): void
    {
        $service = $this->fixture();
        $this->create($service);
        $before = DB::table('users')->get()->toJson();
        try {
            $this->create($service, 'another@example.test');
            $this->fail('Second bootstrap must fail.');
        } catch (RuntimeException) {
            $this->assertSame($before, DB::table('users')->get()->toJson());
            $this->assertDatabaseCount('audit_logs', 1);
            $this->assertSame(2, $service->releases);
        }
    }

    public function test_audit_tombstone_survives_synthetic_account_removal_and_still_prevents_reuse(): void
    {
        $this->create($this->fixture());
        User::firstOrFail()->forceDelete(); // Disposable SQLite fixture only; never an operator recovery procedure.
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(AuditLog::firstOrFail()->actor_user_id);
        try {
            $this->create($this->fixture());
            $this->fail('Retained audit marker must disable bootstrap even after account removal.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 1);
        }
    }

    public static function malformedIdentity(): array
    {
        return [['', 'first@example.test'], [str_repeat('X', 256), 'first@example.test'], ['Synthetic', ''],
            ['Synthetic', 'not-an-email'], ["Synthetic\nStaff", 'first@example.test']];
    }

    #[DataProvider('malformedIdentity')]
    public function test_invalid_identity_cannot_enter_the_transaction(string $name, string $email): void
    {
        $service = $this->fixture();
        $this->app->instance('env', 'production');
        try {
            $service->create($name, $email, self::PASSWORD);
            $this->fail('Malformed identity must be refused.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame(0, $service->checks);
        }
    }

    public static function badPasswords(): array
    {
        return [[''], ['Short1!'], ['lowercase123!'], ['UPPERCASE123!'], ['NoNumbersHere!'], ['NoSymbolsHere123'],
            [str_repeat('Aa1!', 19)], ["Valid-Password-123!\0"]];
    }

    #[DataProvider('badPasswords')]
    public function test_staff_password_policy_is_enforced_before_writes(string $password): void
    {
        try {
            $this->create($this->fixture(), password: $password);
            $this->fail('Invalid password must fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_busy_lock_has_no_writes_and_does_not_release_another_owners_lock(): void
    {
        $service = $this->fixture();
        $service->busy = true;
        try {
            $this->create($service);
            $this->fail('Busy lock must prevent bootstrap.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertSame(0, $service->releases);
        }
    }

    public static function lockChecks(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('lockChecks')]
    public function test_lost_lock_rolls_back_and_releases_at_either_transaction_boundary(int $at): void
    {
        $service = $this->fixture();
        $service->failCheck = $at;
        try {
            $this->create($service);
            $this->fail('Lost lock must prevent commit.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame(1, $service->releases);
        }
    }

    public function test_audit_failure_rolls_back_the_account(): void
    {
        AuditLog::creating(static function (): void {
            throw new RuntimeException('Synthetic audit failure.');
        });
        $service = $this->fixture();
        try {
            $this->create($service);
            $this->fail('Account/audit must be atomic.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame(1, $service->releases);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_real_lock_boundary_refuses_sqlite_instead_of_approximating_mysql_safety(): void
    {
        try {
            $this->create(new FirstSuperAdminBootstrap);
            $this->fail('MySQL lock must not be approximated.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('MySQL', $error->getMessage());
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_mysql_lock_queries_use_one_primary_connection_and_verify_ownership(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('selectOne')->once()->with('SELECT GET_LOCK(?, 0) AS acquired', ['synthetic-lock'], false)->andReturn((object) ['acquired' => 1]);
        $connection->shouldReceive('selectOne')->once()->with('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS connection_id', ['synthetic-lock'], false)
            ->andReturn((object) ['owner' => 42, 'connection_id' => 42]);
        $connection->shouldReceive('selectOne')->once()->with('SELECT RELEASE_LOCK(?) AS released', ['synthetic-lock'], false)->andReturn((object) ['released' => 1]);
        $service = new class extends FirstSuperAdminBootstrap
        {
            public function exercise(Connection $db): bool
            {
                $acquired = $this->acquire($db, 'synthetic-lock');
                $this->assertOwned($db, 'synthetic-lock');
                $this->release($db, 'synthetic-lock');

                return $acquired;
            }
        };
        $this->assertTrue($service->exercise($connection));
    }

    public function test_noninteractive_cli_has_no_writes_or_credential_arguments(): void
    {
        $this->app->instance('env', 'production');
        $this->assertSame(1, Artisan::call('admins:bootstrap-first-super-admin', ['--no-interaction' => true]));
        $this->assertDatabaseCount('users', 0);
        $definition = Artisan::all()['admins:bootstrap-first-super-admin']->getDefinition();
        foreach (['password', 'email', 'name', 'force'] as $option) {
            $this->assertFalse($definition->hasOption($option));
            $this->assertFalse($definition->hasArgument($option));
        }
        $this->assertNotEmpty((new \ReflectionMethod(FirstSuperAdminBootstrap::class, 'create'))->getParameters()[2]->getAttributes(\SensitiveParameter::class));
    }

    public static function consoleOutcomes(): array
    {
        return [['success'], ['mismatch'], ['failure']];
    }

    #[DataProvider('consoleOutcomes')]
    public function test_hidden_only_cli_prompts_and_private_error_output(string $outcome): void
    {
        $service = $this->fixture();
        if ($outcome === 'failure') {
            $service = Mockery::mock(FirstSuperAdminBootstrap::class);
            $service->shouldReceive('create')->once()->andThrow(new RuntimeException('R6-PRIVATE-EXCEPTION-CANARY'));
        }
        $this->instance(FirstSuperAdminBootstrap::class, $service);
        $command = new class extends BootstrapFirstSuperAdmin
        {
            protected $signature = 'admins:bootstrap-first-super-admin';

            protected function privateTerminal(): bool
            {
                return true;
            }
        };
        $command->setLaravel($this->app);
        $this->app->instance('env', 'production');
        $buffer = new BufferedOutput;
        $style = Mockery::mock(OutputStyle::class.'[askQuestion]', [new ArrayInput([]), $buffer]);
        foreach (['Staff name' => 'Private Synthetic Staff', 'Staff email' => 'private@example.test', 'Initial password' => self::PASSWORD,
            'Confirm initial password' => $outcome === 'mismatch' ? 'Different-Synthetic-123!' : self::PASSWORD] as $question => $answer) {
            $style->shouldReceive('askQuestion')->once()->ordered()->with(Mockery::on(fn ($prompt) => $prompt->getQuestion() === $question && $prompt->isHidden() && ! $prompt->isHiddenFallback()))->andReturn($answer);
        }
        Log::spy();
        // Symfony intentionally disables interaction for non-TTY Artisan::call. Supply only a synthetic interactive input here.
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $this->assertSame($outcome === 'success' ? 0 : 1, $command->run($input, $style));
        $output = $buffer->fetch();
        foreach (['Private Synthetic Staff', 'private@example.test', self::PASSWORD, 'R6-PRIVATE-EXCEPTION-CANARY'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        if ($outcome === 'failure') {
            Log::shouldHaveReceived('error')->once()->with('First staff bootstrap did not complete normally. Inspect approved account/audit state before retrying.');
        }
        $this->assertDatabaseCount('users', $outcome === 'success' ? 1 : 0);
        $this->assertDatabaseCount('audit_logs', $outcome === 'success' ? 1 : 0);
    }
}
