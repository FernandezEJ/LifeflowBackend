<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Models\AuditLog;
use App\Models\Reward;
use App\Models\User;
use App\Services\Admin\AdminManagementService;
use App\Services\PasswordResetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(now()->startOfSecond());
    }

    private function signIn(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->withToken($user->createToken('admin-web')->plainTextToken);

        return $user;
    }

    public function test_list_search_and_status_filters_exclude_donors_and_super_admins(): void
    {
        $this->signIn();
        User::factory()->create(['name' => 'Hidden Donor']);
        $active = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Alice Admin', 'email' => 'alice@example.test']);
        $inactive = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Bob Admin', 'deactivated_at' => now()->subDays(2)]);

        $all = $this->getJson('/api/admin/admins')->assertOk()->assertJsonCount(2, 'admins')
            ->assertJsonPath('summary', ['total' => 2, 'active' => 1, 'deactivated' => 1]);
        $this->assertStringNotContainsString($active->password, $all->getContent());
        $all->assertJsonMissingPath('admins.0.password')->assertJsonMissingPath('admins.0.temporary_password');
        $this->getJson('/api/admin/admins?search=ALICE')->assertOk()->assertJsonCount(1, 'admins')->assertJsonPath('admins.0.id', $active->id);
        $this->getJson('/api/admin/admins?search=alice%40example.test')->assertOk()->assertJsonCount(1, 'admins');
        $this->getJson('/api/admin/admins?status=active')->assertOk()->assertJsonPath('admins.0.id', $active->id)->assertJsonCount(1, 'admins');
        $this->getJson('/api/admin/admins?status=deactivated')->assertOk()->assertJsonCount(1, 'admins')
            ->assertJsonPath('admins.0.id', $inactive->id)->assertJsonPath('admins.0.scheduled_for_deletion_at', now()->addDays(28)->toISOString());
        $this->getJson('/api/admin/admins?status=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/admins?search=no-match')->assertOk()->assertJsonCount(0, 'admins');
    }

    public static function deniedOperations(): array
    {
        $cases = [];
        foreach (['admin', 'donor'] as $role) {
            foreach (['list', 'create', 'edit', 'deactivate', 'reactivate', 'send-password-reset'] as $action) {
                $cases[$role.' '.$action] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedOperations')]
    public function test_non_super_admins_are_denied_all_management_operations(string $role, string $action): void
    {
        $this->signIn($role);
        $target = User::factory()->create(['role' => UserRole::Admin]);
        $path = '/api/admin/admins';
        $response = match ($action) {
            'list' => $this->getJson($path),
            'create' => $this->postJson($path, ['name' => 'New', 'email' => 'new@example.test']),
            'edit' => $this->putJson($path.'/'.$target->id, ['name' => 'Changed', 'email' => 'changed@example.test']),
            default => $this->postJson($path.'/'.$target->id.'/'.$action),
        };

        $response->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_create_response_is_one_time_and_created_admin_appears_in_list(): void
    {
        $this->signIn();
        $created = $this->postJson('/api/admin/admins', ['name' => 'New Admin', 'email' => 'new@example.test'])->assertCreated();
        $password = $created->json('temporary_password');
        $this->assertNotEmpty($password);

        $list = $this->getJson('/api/admin/admins')->assertOk()->assertJsonCount(1, 'admins')->assertJsonPath('admins.0.email', 'new@example.test');

        $this->assertStringNotContainsString($password, $list->getContent());
        $this->postJson('/api/admin/admins', ['name' => 'Duplicate', 'email' => 'new@example.test'])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('admin_created', AuditLog::sole()->action);
    }

    public function test_edit_changes_only_name_email_and_logs_without_secrets(): void
    {
        $this->signIn();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDay()]);
        $token = $admin->createToken('test');
        $hash = $admin->password;

        $this->putJson('/api/admin/admins/'.$admin->id, ['name' => 'Updated', 'email' => 'UPDATED@example.test'])
            ->assertOk()->assertJsonPath('admin.email', 'updated@example.test')->assertJsonPath('admin.status', 'deactivated');

        $admin->refresh();
        $this->assertSame($hash, $admin->password);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertSame('admin_updated', AuditLog::sole()->action);
        $this->assertNull(AuditLog::sole()->details);
        $this->assertStringNotContainsString($hash, AuditLog::sole()->toJson());
        $this->putJson('/api/admin/admins/'.$admin->id, ['name' => 'Updated', 'email' => 'updated@example.test'])->assertOk();
    }

    public static function protectedFields(): array
    {
        return [['role', 'super_admin'], ['password', 'Attempt123!'], ['must_change_password', false], ['deactivated_at', '2026-09-01'], ['deleted_at', null]];
    }

    #[DataProvider('protectedFields')]
    public function test_edit_rejects_server_owned_fields(string $field, mixed $value): void
    {
        $this->signIn();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->putJson('/api/admin/admins/'.$admin->id, ['name' => 'Changed', 'email' => $admin->email, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_email_cannot_be_used_for_edit(): void
    {
        $this->signIn();
        $donor = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->putJson('/api/admin/admins/'.$admin->id, ['name' => 'Duplicate', 'email' => $donor->email])->assertUnprocessable();
        $this->assertNotSame($donor->email, $admin->fresh()->email);
    }

    public static function invalidTargets(): array
    {
        return [['donor'], ['super_admin']];
    }

    #[DataProvider('invalidTargets')]
    public function test_ordinary_management_cannot_mutate_donor_or_super_admin(string $role): void
    {
        $this->signIn();
        $target = User::factory()->create(['role' => $role]);
        $this->putJson('/api/admin/admins/'.$target->id, ['name' => 'Changed', 'email' => $target->email])->assertNotFound();
        foreach (['deactivate', 'reactivate', 'send-password-reset'] as $action) {
            $this->postJson('/api/admin/admins/'.$target->id.'/'.$action)->assertNotFound();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deactivate_reactivate_revokes_tokens_and_requires_new_login(): void
    {
        $super = $this->signIn();
        $superToken = $super->tokens()->first();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'password' => 'Password123!']);
        $old = $admin->createToken('admin-web');
        $admin->createToken('mobile');
        $this->postJson('/api/admin/admins/'.$admin->id.'/deactivate')->assertOk()
            ->assertJsonPath('admin.scheduled_for_deletion_at', now()->addDays(30)->toISOString());
        $this->assertTrue($admin->fresh()->isDeactivated());
        $this->assertFalse($admin->fresh()->trashed());
        $this->assertSame(0, $admin->tokens()->count());
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'Password123!'])->assertUnauthorized();
        $this->postJson('/api/admin/admins/'.$admin->id.'/deactivate')->assertConflict();
        $this->postJson('/api/admin/admins/'.$admin->id.'/reactivate')->assertOk()->assertJsonPath('admin.deactivated_at', null);
        $this->postJson('/api/admin/admins/'.$admin->id.'/reactivate')->assertConflict();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'Password123!'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($old->plainTextToken)->getJson('/api/admin/me')->assertUnauthorized();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $superToken->id]);
        $this->assertSame(['admin_deactivated', 'admin_reactivated'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_reactivation_ends_at_exactly_thirty_days(): void
    {
        $this->signIn();
        $expired = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(30)]);
        $this->postJson('/api/admin/admins/'.$expired->id.'/reactivate')->assertConflict();
        $this->assertTrue($expired->fresh()->isDeactivated());
    }

    public function test_purge_removes_only_expired_admins_and_preserves_audit_history(): void
    {
        $expired = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(30)]);
        $expired->createToken('old');
        $log = $expired->auditLogs()->create(['actor_role' => UserRole::Admin, 'action' => 'old_action', 'module' => 'test']);
        $retained = [
            User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(30)->addSecond()]),
            User::factory()->create(['role' => UserRole::Admin]),
            User::factory()->create(['role' => UserRole::Donor, 'deactivated_at' => now()->subDays(40)]),
            User::factory()->create(['role' => UserRole::SuperAdmin, 'deactivated_at' => now()->subDays(40)]),
        ];
        $this->artisan('admins:purge-deactivated')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $expired->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($log->fresh()->actor_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_purged', 'target_id' => $expired->id]);
        foreach ($retained as $user) {
            $this->assertModelExists($user);
        }
        $this->artisan('admins:purge-deactivated')->assertExitCode(0);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_purge_retains_accounts_owning_donation_points_or_redemption_history(): void
    {
        $donations = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(40)]);
        DB::table('donation_records')->insert(['user_id' => $donations->id, 'donation_date' => now()->toDateString(), 'location' => 'Test', 'submitted_at' => now()]);
        $points = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(40)]);
        DB::table('point_transactions')->insert(['user_id' => $points->id, 'type' => 'donation_reward', 'amount' => 100, 'event_key' => 'test']);
        $vouchers = User::factory()->create(['role' => UserRole::Admin, 'deactivated_at' => now()->subDays(40)]);
        DB::table('user_vouchers')->insert(['user_id' => $vouchers->id, 'reward_id' => Reward::factory()->create()->id,
            'points_spent' => 100, 'voucher_token' => 'test-token', 'redemption_key' => 'test-key']);

        $this->assertSame(['purged' => 0, 'retained' => 3], app(AdminManagementService::class)->purge());

        foreach (['donation_records', 'point_transactions', 'user_vouchers'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_purge_is_registered_daily_in_calendar_timezone(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'admins:purge-deactivated'));
        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertSame(config('app.calendar_timezone'), $events->first()->timezone);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_reset_trigger_sends_existing_email_without_returning_or_auditing_secrets(): void
    {
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $this->signIn();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $hash = $admin->password;

        $response = $this->postJson('/api/admin/admins/'.$admin->id.'/send-password-reset')->assertAccepted()
            ->assertExactJson(['message' => PasswordResetService::NOTICE]);
        Queue::assertPushed(SendPasswordResetCode::class);
        $row = DB::table('password_reset_codes')->where('email_hash', hash('sha256', strtolower($admin->email)))->first();
        app(PasswordResetService::class)->deliver(strtolower($admin->email), $row->request_id);
        Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo($admin->email));
        $code = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString($code, AuditLog::sole()->toJson());
        $this->assertSame('admin_password_reset_requested', AuditLog::sole()->action);
        $this->assertNull(AuditLog::sole()->details);
        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_deactivated_admin_cannot_receive_a_reset_even_if_previously_queued(): void
    {
        Queue::fake();
        Mail::fake();
        $this->signIn();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->postJson('/api/admin/admins/'.$admin->id.'/send-password-reset')->assertAccepted();
        $row = DB::table('password_reset_codes')->first();
        $admin->forceFill(['deactivated_at' => now()])->save();
        $this->postJson('/api/admin/admins/'.$admin->id.'/send-password-reset')->assertConflict();
        app(PasswordResetService::class)->deliver(strtolower($admin->email), $row->request_id);

        Mail::assertNothingSent();
        $this->assertNotNull(DB::table('password_reset_codes')->first()->used_at);
    }

    public function test_guest_and_pending_password_accounts_cannot_manage(): void
    {
        $this->getJson('/api/admin/admins')->assertUnauthorized();
        $user = $this->signIn();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/admin/admins')->assertForbidden()->assertJsonPath('reason', 'password_change_required');

    }
}
