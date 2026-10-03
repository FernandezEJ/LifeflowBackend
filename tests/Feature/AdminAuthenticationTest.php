<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    public static function panelRoles(): array
    {
        return ['admin' => ['admin', false], 'super admin' => ['super_admin', true]];
    }

    #[DataProvider('panelRoles')]
    public function test_panel_login_issues_a_token_updates_last_login_and_returns_only_safe_fields(string $role, bool $mustChange): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['email' => 'staff@example.test', 'password' => 'test-password',
            'role' => $role, 'must_change_password' => $mustChange]);

        $response = $this->postJson('/api/admin/login', ['email' => ' STAFF@example.test ', 'password' => 'test-password']);

        $response->assertOk()->assertJsonPath('user.role', $role)
            ->assertJsonPath('user.must_change_password', $mustChange);
        $this->assertEquals(now(), $user->fresh()->last_login_at);
        $response->assertExactJson(['token' => $response->json('token'), 'user' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $role, 'must_change_password' => $mustChange, 'last_login_at' => now()->toISOString(),
        ]]);
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($token);
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertSame('admin-web', $token->name);
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/admin/me')->assertOk()
            ->assertExactJson(['user' => $response->json('user')]);
    }

    public static function rejectedLogins(): array
    {
        return [
            'donor' => ['donor', false, false, 'test-password', 'staff@example.test'],
            'wrong password' => ['admin', false, false, 'wrong-password', 'staff@example.test'],
            'unknown email' => ['admin', false, false, 'test-password', 'missing@example.test'],
            'deactivated admin' => ['admin', true, false, 'test-password', 'staff@example.test'],
            'deactivated super admin' => ['super_admin', true, false, 'test-password', 'staff@example.test'],
            'deleted admin' => ['admin', false, true, 'test-password', 'staff@example.test'],
            'deleted super admin' => ['super_admin', false, true, 'test-password', 'staff@example.test'],
        ];
    }

    #[DataProvider('rejectedLogins')]
    public function test_invalid_accounts_receive_the_same_401_without_a_token_or_login_update(string $role, bool $deactivated, bool $deleted, string $password, string $email): void
    {
        $user = User::factory()->create(['role' => $role, 'email' => 'staff@example.test', 'password' => 'test-password',
            'last_login_at' => '2026-01-01 00:00:00',
            'deactivated_at' => $deactivated ? '2026-09-01' : null,
            'deleted_at' => $deleted ? '2026-09-01' : null]);

        $this->postJson('/api/admin/login', ['email' => $email, 'password' => $password])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid email or password.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('2026-01-01 00:00:00', User::withTrashed()->findOrFail($user->id)->last_login_at->toDateTimeString());
    }

    public static function invalidLoginPayloads(): array
    {
        return [
            'empty' => [[], ['email', 'password']],
            'email malformed' => [['email' => 'invalid', 'password' => 'test-password'], ['email']],
            'email array' => [['email' => ['invalid'], 'password' => 'test-password'], ['email']],
            'password array' => [['email' => 'staff@example.test', 'password' => ['invalid']], ['password']],
        ];
    }

    #[DataProvider('invalidLoginPayloads')]
    public function test_invalid_login_payload_returns_422(array $payload, array $errors): void
    {
        $this->postJson('/api/admin/login', $payload)->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_admin_endpoints_require_a_bearer_token(): void
    {
        $this->get('/api/admin/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->post('/api/admin/logout')->assertUnauthorized();
        $this->withToken('invalid-token')->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_donor_mobile_token_cannot_access_admin_routes_but_keeps_mobile_access(): void
    {
        $donor = User::factory()->create(['password' => 'test-password']);
        $login = $this->postJson('/api/login', ['email' => $donor->email, 'password' => 'test-password'])->assertOk();
        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/admin/me')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/admin/logout')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $donor->id);

        $this->assertSame('mobile', PersonalAccessToken::findToken($token)->name);
    }

    #[DataProvider('panelRoles')]
    public function test_deactivation_after_token_issuance_denies_access_and_revokes_only_current_token(string $role, bool $mustChange): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $mustChange]);
        $current = $user->createToken('admin-web');
        $other = $user->createToken('admin-web');
        $user->forceFill(['deactivated_at' => '2026-09-01'])->save();

        $this->withToken($current->plainTextToken)->getJson('/api/admin/me')->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_soft_deleted_account_cannot_use_an_old_token(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $token = $user->createToken('admin-web');
        $user->delete();

        $this->withToken($token->plainTextToken)->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_role_demotion_blocks_an_existing_admin_token(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $token = $user->createToken('admin-web');
        $user->role = UserRole::Donor;
        $user->save();

        $this->withToken($token->plainTextToken)->getJson('/api/admin/me')->assertForbidden();
    }

    #[DataProvider('panelRoles')]
    public function test_logout_revokes_only_the_current_token_and_blocks_reuse(string $role, bool $mustChange): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $mustChange]);
        $current = $user->createToken('admin-web');
        $other = $user->createToken('admin-web');

        $this->withToken($current->plainTextToken)->postJson('/api/admin/logout')->assertOk()
            ->assertExactJson(['message' => 'Logged out successfully.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/admin/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/admin/me')->assertOk();
    }

    public static function restrictedRoles(): array
    {
        return ['donor' => ['donor', 403], 'admin' => ['admin', 403], 'super admin' => ['super_admin', 200]];
    }

    #[DataProvider('restrictedRoles')]
    public function test_super_admin_middleware_option_allows_only_super_admin(string $role, int $status): void
    {
        Route::get('/api/test/super-admin', fn () => response()->json(['allowed' => true]))
            ->middleware(['api', 'auth:sanctum', 'admin.panel:super_admin']);
        $user = User::factory()->create(['role' => $role]);
        $token = $user->createToken('admin-web');

        $this->withToken($token->plainTextToken)->getJson('/api/test/super-admin')->assertStatus($status);
    }

    public function test_super_admin_middleware_option_rejects_deactivated_super_admin(): void
    {
        Route::get('/api/test/super-admin', fn () => response()->json(['allowed' => true]))
            ->middleware(['api', 'auth:sanctum', 'admin.panel:super_admin']);
        $user = User::factory()->create(['role' => UserRole::SuperAdmin, 'deactivated_at' => '2026-09-01']);
        $token = $user->createToken('admin-web');

        $this->withToken($token->plainTextToken)->getJson('/api/test/super-admin')->assertForbidden();
    }

    public function test_unknown_middleware_role_fails_closed(): void
    {
        Route::get('/api/test/invalid-role', fn () => response()->json(['allowed' => true]))
            ->middleware(['api', 'auth:sanctum', 'admin.panel:unknown']);
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->withToken($user->createToken('admin-web')->plainTextToken)->getJson('/api/test/invalid-role')->assertForbidden();
    }

    public function test_email_and_ip_login_limit_does_not_consume_donor_login_limit(): void
    {
        $this->freezeTime();
        $donor = User::factory()->create(['email' => 'donor@example.test', 'password' => 'test-password']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', ['email' => $donor->email, 'password' => 'test-password'])->assertUnauthorized();
        }
        $this->postJson('/api/admin/login', ['email' => ' DONOR@example.test ', 'password' => 'test-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');

        $this->postJson('/api/login', ['email' => $donor->email, 'password' => 'test-password'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->travel(61)->seconds();
        $this->postJson('/api/admin/login', ['email' => $donor->email, 'password' => 'test-password'])->assertUnauthorized();
    }

    public function test_ip_limit_blocks_rotating_email_attempts(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/admin/login', ['email' => 'missing'.$attempt.'@example.test', 'password' => 'test-password'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/admin/login', ['email' => 'another@example.test', 'password' => 'test-password'])->assertTooManyRequests();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_limit_is_scoped_to_the_ip_address(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', ['email' => 'missing@example.test', 'password' => 'test-password'])->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/api/admin/login', ['email' => 'missing@example.test', 'password' => 'test-password'])->assertUnauthorized();
    }
}
