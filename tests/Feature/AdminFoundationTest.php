<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    public function test_database_defaults_create_an_active_donor(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Default Donor', 'email' => 'default@example.test', 'password' => Hash::make('test-password'),
        ]);

        $user = User::findOrFail($id);

        $this->assertSame(UserRole::Donor, $user->role);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->deactivated_at);
        $this->assertNull($user->last_login_at);
        $this->assertNull($user->deleted_at);
    }

    public function test_verified_mobile_registration_still_creates_a_donor(): void
    {
        $response = $this->registerVerified($this->registrationData());

        $response->assertCreated();
        $user = User::findOrFail($response->json('user.id'));
        $this->assertTrue($user->isDonor());
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk();
        $this->assertDatabaseCount('donor_profiles', 1);
    }

    public static function privilegedRoles(): array
    {
        return ['admin' => ['admin'], 'super admin' => ['super_admin']];
    }

    #[DataProvider('privilegedRoles')]
    public function test_registration_ignores_privileged_role_and_lifecycle_fields(string $role): void
    {
        $response = $this->registerVerified(array_merge($this->registrationData(), [
            'role' => $role, 'must_change_password' => true, 'deactivated_at' => '2026-09-01',
            'last_login_at' => '2026-09-01', 'deleted_at' => '2026-09-01',
        ]));

        $response->assertCreated();
        $user = User::findOrFail($response->json('user.id'));
        $this->assertTrue($user->isDonor());
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->deactivated_at);
        $this->assertNull($user->last_login_at);
        $this->assertNull($user->deleted_at);
    }

    #[DataProvider('privilegedRoles')]
    public function test_profile_update_cannot_assign_a_privileged_role(string $role): void
    {
        $registration = $this->registerVerified($this->registrationData())->assertCreated();

        $this->withToken($registration->json('token'))->putJson('/api/donor-profile', [
            'first_name' => 'Updated', 'role' => $role, 'must_change_password' => true,
            'deactivated_at' => '2026-09-01', 'deleted_at' => '2026-09-01',
        ])->assertOk();

        $user = User::findOrFail($registration->json('user.id'));
        $this->assertTrue($user->isDonor());
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->deactivated_at);
        $this->assertSame('Updated', $user->donorProfile->first_name);
    }

    public function test_mass_assignment_cannot_change_server_owned_user_fields(): void
    {
        $user = User::factory()->create();

        $user->fill(['name' => 'Updated', 'role' => 'super_admin', 'must_change_password' => true,
            'deactivated_at' => '2026-09-01', 'last_login_at' => '2026-09-01', 'deleted_at' => '2026-09-01'])->save();

        $user->refresh();
        $this->assertSame('Updated', $user->name);
        $this->assertTrue($user->isDonor());
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->deactivated_at);
        $this->assertNull($user->last_login_at);
        $this->assertNull($user->deleted_at);
    }

    public static function roleMatrix(): array
    {
        return [
            'donor' => ['donor', true, false, false, false],
            'admin' => ['admin', false, true, false, true],
            'super admin' => ['super_admin', false, false, true, true],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_role_helpers_distinguish_donors_and_panel_users(string $role, bool $donor, bool $admin, bool $superAdmin, bool $panel): void
    {
        $user = User::factory()->create(['role' => $role])->fresh();

        $this->assertSame([$donor, $admin, $superAdmin, $panel], [
            $user->isDonor(), $user->isAdmin(), $user->isSuperAdmin(), $user->isAdminPanelUser(),
        ]);
    }

    public function test_deactivation_and_login_dates_are_cast_without_deleting_the_user(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->isDeactivated());

        $user->forceFill(['deactivated_at' => '2026-09-01 10:00:00', 'last_login_at' => '2026-08-31 09:00:00'])->save();
        $user->refresh();

        $this->assertTrue($user->isDeactivated());
        $this->assertSame('2026-09-01', $user->deactivated_at->toDateString());
        $this->assertSame('2026-08-31', $user->last_login_at->toDateString());
        $this->assertFalse($user->trashed());
    }

    public function test_audit_history_survives_actor_soft_delete_restore_and_permanent_deletion(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $log = $actor->auditLogs()->create([
            'actor_role' => UserRole::Admin, 'action' => 'foundation_test', 'module' => 'test',
            'target_type' => 'user', 'target_id' => $actor->id, 'details' => ['description' => 'Test history'],
        ]);
        $this->assertSame($actor->id, $log->actor->id);
        $this->assertSame(['description' => 'Test history'], $log->fresh()->details);

        $actor->delete();

        $this->assertSoftDeleted($actor);
        $this->assertNull(User::find($actor->id));
        $this->assertTrue($log->fresh()->actor->trashed());
        $actor->restore();
        $this->assertNotNull(User::find($actor->id));
        $actor->forceDelete();

        $log->refresh();
        $this->assertNull($log->actor_user_id);
        $this->assertNull($log->actor);
        $this->assertSame(UserRole::Admin, $log->actor_role);
        $this->assertSame($actor->id, $log->target_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_log_allows_an_absent_actor_and_optional_details(): void
    {
        $log = AuditLog::create(['actor_role' => UserRole::SuperAdmin, 'action' => 'test', 'module' => 'test']);

        $this->assertModelExists($log);
        $this->assertNull($log->fresh()->actor);
        $this->assertNull($log->details);
    }

    public function test_super_admin_seeder_is_idempotent_and_hashes_the_temporary_password(): void
    {
        $this->configureSeeder();

        $this->seed(SuperAdminSeeder::class);
        $first = User::sole();
        $this->seed(SuperAdminSeeder::class);
        $second = User::sole();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->password, $second->password);
        $this->assertSame('First Super Admin', $second->name);
        $this->assertSame('super@example.test', $second->email);
        $this->assertSame(UserRole::SuperAdmin, $second->role);
        $this->assertTrue(Hash::check('test-only-password', $second->password));
        $this->assertNotSame('test-only-password', $second->password);
        $this->assertTrue($second->must_change_password);
        $this->assertNull($second->deactivated_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_seeder_updates_only_the_configured_super_admin(): void
    {
        $this->configureSeeder();
        $this->seed(SuperAdminSeeder::class);
        $user = User::sole();
        $user->forceFill(['must_change_password' => false, 'deactivated_at' => '2026-09-01'])->save();
        config(['admin.super_admin.name' => 'Updated Super Admin', 'admin.super_admin.password' => 'changed-test-password']);

        $this->seed(SuperAdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $user->refresh();
        $this->assertSame('Updated Super Admin', $user->name);
        $this->assertTrue(Hash::check('changed-test-password', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->deactivated_at);
    }

    public static function invalidSeedConfiguration(): array
    {
        return [
            'missing name' => ['name', null],
            'missing email' => ['email', ''],
            'invalid email' => ['email', 'invalid'],
            'missing password' => ['password', null],
            'short password' => ['password', 'short'],
            'too long password' => ['password', str_repeat('a', 73)],
            'null byte password' => ['password', "password\0test"],
        ];
    }

    #[DataProvider('invalidSeedConfiguration')]
    public function test_seeder_rejects_invalid_configuration_without_creating_accounts(string $key, mixed $value): void
    {
        $this->configureSeeder();
        config(['admin.super_admin.'.$key => $value]);

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('Invalid seed configuration must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Set valid SUPER_ADMIN_NAME', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_seeder_does_not_promote_an_existing_donor(): void
    {
        $this->configureSeeder();
        $donor = User::factory()->create(['email' => 'super@example.test']);
        $original = $donor->fresh()->getAttributes();

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('An existing donor must not be promoted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing non-Super-Admin or deleted account', $exception->getMessage());
        }

        $this->assertSame($original, $donor->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_seeder_does_not_create_a_second_super_admin(): void
    {
        $this->configureSeeder();
        $existing = User::factory()->create(['role' => UserRole::SuperAdmin]);

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('A second Super Admin must not be created.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different Super Admin already exists', $exception->getMessage());
        }

        $this->assertSame($existing->id, User::sole()->id);
    }

    public function test_seeder_does_not_restore_a_deleted_super_admin(): void
    {
        $this->configureSeeder();
        $user = User::factory()->create(['email' => 'super@example.test', 'role' => UserRole::SuperAdmin]);
        $user->delete();

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('A deleted account must not be restored by the seeder.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deleted account', $exception->getMessage());
        }

        $this->assertSoftDeleted($user);
        $this->assertDatabaseCount('users', 1);
    }

    private function configureSeeder(): void
    {
        config(['admin.super_admin' => [
            'name' => 'First Super Admin', 'email' => ' SUPER@example.test ', 'password' => 'test-only-password',
        ]]);
    }

    private function registrationData(): array
    {
        return [
            'first_name' => 'Test', 'last_name' => 'Donor', 'mobile_number' => '09171234567',
            'birth_date' => '2004-09-17', 'gender' => 'Male', 'blood_type' => 'O+',
            'email' => 'testdonor@example.test', 'password' => 'password123', 'password_confirmation' => 'password123',
            'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
        ];
    }
}
