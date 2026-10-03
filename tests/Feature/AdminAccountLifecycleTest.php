<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AdminAccountService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminAccountLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Route::get('/api/test/admin-feature', fn () => response()->json(['allowed' => true]))
            ->middleware(['api', 'auth:sanctum', 'admin.panel', 'admin.password-changed']);
    }

    public function test_super_admin_creates_admin_with_a_one_time_temporary_password_and_safe_audit(): void
    {
        $super = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->signIn($super);
        Log::spy();

        $response = $this->postJson('/api/admin/admins', ['name' => 'New Admin', 'email' => ' NEW@example.test '])->assertCreated();

        $admin = User::where('email', 'new@example.test')->firstOrFail();
        $temporary = $response->json('temporary_password');
        $this->assertSame(20, strlen($temporary));
        $this->assertMatchesRegularExpression('/[A-Z]/', $temporary);
        $this->assertMatchesRegularExpression('/[a-z]/', $temporary);
        $this->assertMatchesRegularExpression('/[0-9]/', $temporary);
        $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $temporary);
        $this->assertTrue(Hash::check($temporary, $admin->password));
        $this->assertNotSame($temporary, $admin->password);
        $this->assertNull($admin->deactivated_at);
        $this->assertNull($admin->last_login_at);
        $response->assertExactJson(['message' => 'Admin account created.', 'admin' => [
            'id' => $admin->id, 'name' => 'New Admin', 'email' => 'new@example.test',
            'role' => 'admin', 'must_change_password' => true,
        ], 'temporary_password' => $temporary]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('temporary_password', $admin->getAttributes());
        $this->assertArrayNotHasKey('password', $admin->toArray());
        $this->assertStringNotContainsString($temporary, $admin->toJson());
        $log = AuditLog::sole();
        $this->assertSame('admin_created', $log->action);
        $this->assertSame($super->id, $log->actor_user_id);
        $this->assertSame(UserRole::SuperAdmin, $log->actor_role);
        $this->assertSame($admin->id, $log->target_id);
        $this->assertNull($log->details);
        $this->assertStringNotContainsString($temporary, $log->toJson());
        $this->assertStringNotContainsString($admin->password, $log->toJson());
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => $temporary])->assertOk();
        $this->app['auth']->forgetGuards();
        $me = $this->withToken($login->json('token'))->getJson('/api/admin/me')->assertOk()
            ->assertJsonPath('user.must_change_password', true)->assertJsonMissingPath('temporary_password');
        $this->assertStringNotContainsString($temporary, $me->getContent());
        $this->assertStringNotContainsString($temporary, $login->getContent());
    }

    public static function deniedCreators(): array
    {
        return [
            'admin' => ['admin', false, false, false, 403],
            'donor' => ['donor', false, false, false, 403],
            'deactivated super' => ['super_admin', true, false, false, 403],
            'deleted super' => ['super_admin', false, true, false, 401],
            'initial password pending' => ['super_admin', false, false, true, 403],
        ];
    }

    #[DataProvider('deniedCreators')]
    public function test_only_active_password_ready_super_admin_can_create(string $role, bool $deactivated, bool $deleted, bool $mustChange, int $status): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $mustChange,
            'deactivated_at' => $deactivated ? '2026-09-01' : null, 'deleted_at' => $deleted ? '2026-09-01' : null]);
        $this->signIn($user);

        $this->postJson('/api/admin/admins', ['name' => 'New Admin', 'email' => 'new@example.test'])->assertStatus($status);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_both_lifecycle_endpoints_require_authentication(): void
    {
        $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.test'])->assertUnauthorized();
        $this->postJson('/api/admin/change-initial-password', $this->passwordPayload())->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidCreation(): array
    {
        return [
            'name missing' => [['name' => ''], 'name'],
            'name long' => [['name' => str_repeat('a', 256)], 'name'],
            'email invalid' => [['email' => 'invalid'], 'email'],
            'client role' => [['role' => 'super_admin'], 'role'],
            'null role' => [['role' => null], 'role'],
            'chosen password' => [['password' => 'Chosen-Password123!'], 'password'],
            'chosen temporary' => [['temporary_password' => 'Chosen-Password123!'], 'temporary_password'],
        ];
    }

    #[DataProvider('invalidCreation')]
    public function test_invalid_or_server_owned_create_fields_return_422(array $override, string $field): void
    {
        $this->signIn(User::factory()->create(['role' => UserRole::SuperAdmin]));

        $this->postJson('/api/admin/admins', array_merge(['name' => 'New', 'email' => 'new@example.test'], $override))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_email_including_deleted_users_is_rejected_without_reissuing_password(): void
    {
        $this->signIn(User::factory()->create(['role' => UserRole::SuperAdmin]));
        $existing = User::factory()->create(['email' => 'existing@example.test', 'deleted_at' => '2026-09-01']);
        $hash = $existing->password;

        $this->postJson('/api/admin/admins', ['name' => 'Duplicate', 'email' => 'EXISTING@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingPath('temporary_password');

        $this->assertSame($hash, User::withTrashed()->findOrFail($existing->id)->password);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function panelRoles(): array
    {
        return ['admin' => ['admin'], 'super admin' => ['super_admin']];
    }

    #[DataProvider('panelRoles')]
    public function test_initial_password_change_unlocks_features_preserves_current_token_and_revokes_others(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'Temporary123!', 'must_change_password' => true]);
        $current = $this->signIn($user);
        $other = $user->createToken('admin-web');
        $mobile = $user->createToken('mobile');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/test/admin-feature')->assertForbidden()->assertExactJson([
            'message' => 'Password change required.', 'reason' => 'password_change_required',
        ]);

        $this->postJson('/api/admin/change-initial-password', $this->passwordPayload())->assertOk()
            ->assertExactJson(['message' => 'Password changed successfully.']);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Replacement123!', $user->password));
        $this->assertFalse(Hash::check('Temporary123!', $user->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mobile->accessToken->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $log = AuditLog::sole();
        $this->assertSame('initial_password_changed', $log->action);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertNull($log->details);
        foreach (['Temporary123!', 'Replacement123!', $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $log->toJson());
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/test/admin-feature')->assertOk();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/admin/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Temporary123!'])->assertUnauthorized();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Replacement123!'])->assertOk();
    }

    public function test_initial_password_pending_user_can_still_log_out(): void
    {
        $this->signIn(User::factory()->create(['role' => UserRole::Admin, 'must_change_password' => true]));

        $this->postJson('/api/admin/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidPasswords(): array
    {
        return [
            'wrong current' => [['current_password' => 'Incorrect123!'], 'current_password'],
            'weak' => [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
            'no upper' => [['password' => 'lowercase123!', 'password_confirmation' => 'lowercase123!'], 'password'],
            'no lower' => [['password' => 'UPPERCASE123!', 'password_confirmation' => 'UPPERCASE123!'], 'password'],
            'no number' => [['password' => 'PasswordOnly!', 'password_confirmation' => 'PasswordOnly!'], 'password'],
            'no symbol' => [['password' => 'PasswordOnly123', 'password_confirmation' => 'PasswordOnly123'], 'password'],
            'mismatch' => [['password_confirmation' => 'Mismatch123!'], 'password'],
            'same password' => [['password' => 'Temporary123!', 'password_confirmation' => 'Temporary123!'], 'password'],
            'missing current' => [['current_password' => ''], 'current_password'],
            'too long' => [['password' => str_repeat('a', 73).'A1!', 'password_confirmation' => str_repeat('a', 73).'A1!'], 'password'],
            'null byte' => [['password' => "NewPassword123!\0", 'password_confirmation' => "NewPassword123!\0"], 'password'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_change_returns_422_without_any_changes(array $override, string $field): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin, 'must_change_password' => true, 'password' => 'Temporary123!']);
        $this->signIn($user);
        $hash = $user->password;

        $this->postJson('/api/admin/change-initial-password', array_merge($this->passwordPayload(), $override))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function deniedPasswordChanges(): array
    {
        return [
            'donor' => ['donor', false, false, true, 403],
            'deactivated admin' => ['admin', true, false, true, 403],
            'deleted admin' => ['admin', false, true, true, 401],
            'already changed' => ['admin', false, false, false, 409],
        ];
    }

    #[DataProvider('deniedPasswordChanges')]
    public function test_password_change_rejects_ineligible_accounts(string $role, bool $deactivated, bool $deleted, bool $mustChange, int $status): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $mustChange, 'password' => 'Temporary123!',
            'deactivated_at' => $deactivated ? '2026-09-01' : null, 'deleted_at' => $deleted ? '2026-09-01' : null]);
        $this->signIn($user);
        $hash = $user->password;

        $this->postJson('/api/admin/change-initial-password', $this->passwordPayload())->assertStatus($status);

        $this->assertSame($hash, User::withTrashed()->findOrFail($user->id)->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_account_creation_rolls_back_if_audit_write_fails(): void
    {
        $super = User::factory()->create(['role' => UserRole::SuperAdmin]);
        AuditLog::creating(function (): void {
            throw new RuntimeException('Simulated audit failure');
        });

        try {
            app(AdminAccountService::class)->create($super, 'New', 'new@example.test');
            $this->fail('Audit failure must roll back account creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_and_token_changes_roll_back_if_audit_write_fails(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin, 'must_change_password' => true, 'password' => 'Temporary123!']);
        $current = $user->createToken('admin-web');
        $user->withAccessToken($current->accessToken);
        $user->createToken('other');
        $hash = $user->password;
        AuditLog::creating(function (): void {
            throw new RuntimeException('Simulated audit failure');
        });

        try {
            app(AdminAccountService::class)->changeInitialPassword($user, 'Temporary123!', 'Replacement123!');
            $this->fail('Audit failure must roll back password and token changes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    private function signIn(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('admin-web')->plainTextToken;
        $this->withToken($token);

        return $token;
    }

    private function passwordPayload(): array
    {
        return ['current_password' => 'Temporary123!', 'password' => 'Replacement123!', 'password_confirmation' => 'Replacement123!'];
    }
}
