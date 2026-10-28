<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureDonorUser;
use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\Notification;
use App\Models\User;
use App\Services\AccountSettingsService;
use App\Services\EligibilityCooldown;
use App\Services\PasswordResetService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class R1AccountBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('proofs');
        Storage::fake('public');
        config(['mail.default' => 'smtp', 'mail.mailers.password_reset.host' => 'smtp.example.test',
            'mail.mailers.password_reset.username' => 'test@example.test', 'mail.mailers.password_reset.password' => 'test-only']);
        $this->freezeTime();
    }

    private function requestAs(?string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => $token ? 'Bearer '.$token : ''])->json($method, '/api/'.$uri, $body);
    }

    private function donor(): User
    {
        $user = User::factory()->create(['password' => 'OldDonor123!']);
        $user->donorProfile()->create(['first_name' => 'Synthetic', 'last_name' => 'Donor',
            'mobile_number' => '+639'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT),
            'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+']);

        return $user;
    }

    private function opportunity(): DonationOpportunity
    {
        return DonationOpportunity::create(['source_type' => 'admin_announcement', 'title' => 'Synthetic activity',
            'description' => 'Test only', 'location' => 'Test facility', 'event_date' => now()->addDay()->toDateString(),
            'status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => now()->addDays(2), 'points_reward' => 300]);
    }

    private function participation(User $owner, DonationOpportunity $opportunity): DonationParticipation
    {
        $item = new DonationParticipation;
        $item->forceFill(['user_id' => $owner->id, 'donation_opportunity_id' => $opportunity->id,
            'source_type' => 'admin_announcement', 'status' => 'pending', 'joined_at' => now()])->save();

        return $item;
    }

    private function notice(User $owner, DonationOpportunity $opportunity): Notification
    {
        return $owner->notifications()->create(['type' => 'admin_announcement', 'title' => 'Synthetic notice',
            'message' => 'Test only', 'event_key' => 'test:'.$owner->id, 'data' => ['opportunity_id' => $opportunity->id]]);
    }

    private function answers(): array
    {
        return ['weightAtLeast50Kg' => 'YES', 'sleptAtLeastFiveHours' => 'YES', 'eatenProperMeal' => 'YES',
            'avoidedAlcoholFor24Hours' => 'YES', 'threeMonthsSinceLastDonation' => 'YES',
            'recentFeverInfectionOrIllness' => 'NO', 'unusualBleedingWeaknessOrDizziness' => 'NO',
            'recentSurgeryOrMajorProcedure' => 'NO', 'medicationAffectingDonation' => 'NO',
            'conditionOrTreatmentRequiringWait' => 'NO'];
    }

    /** Every currently protected donor business route, including the previously protected groups. */
    private function businessRequests(int $opportunity, int $participation, int $notice): array
    {
        $rows = [];
        foreach (['educational-articles', 'educational-articles/1', 'donation-records', 'donation-records/1',
            'donation-summary', 'donation-opportunities', 'announcements', 'announcements/'.$opportunity,
            'donation-opportunities/'.$opportunity, 'donation-opportunities/red-cross-dagupan',
            'donation-participations', 'donation-participations/'.$participation, 'donation-participations/'.$participation.'/proof',
            'eligibility-assessments/latest', 'eligibility-assessments', 'donor-profile', 'notifications',
            'notifications/'.$notice.'/announcement', 'notifications/reminders', 'notifications/'.$notice.'/preparation',
            'notifications/unread-count', 'points/summary', 'points/transactions', 'rewards', 'rewards/1',
            'vouchers', 'vouchers/1', 'flowie/conversations', 'flowie/conversations/recently-deleted', 'flowie/conversations/1'] as $uri) {
            $rows[] = ['GET', $uri, []];
        }
        foreach (['donation-opportunities/'.$opportunity.'/join', 'donation-opportunities/red-cross-dagupan/join',
            'donation-participations/'.$participation.'/cancel', 'donation-participations/'.$participation.'/proof',
            'notifications/'.$notice.'/preparation-acknowledgement', 'notifications/read-all', 'notifications/'.$notice.'/read',
            'device-tokens', 'device-tokens/unregister', 'rewards/1/redeem', 'vouchers/1/activate',
            'profile/change-email/confirm-login', 'profile/change-email/request', 'profile/change-email/resend',
            'profile/change-email/verify', 'profile/change-password', 'flowie/chat', 'flowie/conversations/active/end',
            'flowie/conversations/1/end', 'flowie/conversations/1/restore'] as $uri) {
            $rows[] = ['POST', $uri, []];
        }
        $rows[] = ['POST', 'eligibility-assessments', ['answers' => $this->answers()]];
        $rows[] = ['PUT', 'donor-profile', ['first_name' => 'Blocked']];
        $rows[] = ['PUT', 'donor-profile/avatar', ['profile_avatar' => 'mascot_2']];
        $rows[] = ['DELETE', 'flowie/conversations/1', []];

        return $rows;
    }

    public static function blockedActors(): array
    {
        return array_combine($states = ['guest', 'unverified', 'deactivated', 'deleted', 'revoked', 'admin', 'super_admin',
            'deactivated-admin', 'deactivated-super_admin', 'initial-admin', 'initial-super_admin', 'demoted-staff'], array_map(fn ($state) => [$state], $states));
    }

    private function actor(string $state): array
    {
        if ($state === 'guest') {
            return [null, null, 401];
        }
        $actor = $this->donor();
        $session = $actor->createToken('issued-before-state-change');
        if ($state === 'unverified') {
            $actor->forceFill(['email_verified_at' => null])->save();
        } elseif ($state === 'deactivated') {
            $actor->forceFill(['deactivated_at' => now()])->save();
        } elseif ($state === 'deleted') {
            $actor->delete();
        } elseif ($state === 'revoked') {
            $session->accessToken->delete();
        } else {
            $role = str_contains($state, 'super_admin') ? 'super_admin' : 'admin';
            $actor->forceFill(['role' => $state === 'demoted-staff' ? 'super_admin' : $role,
                'must_change_password' => str_starts_with($state, 'initial-'),
                'deactivated_at' => str_starts_with($state, 'deactivated-') ? now() : null])->save();
            if ($state === 'demoted-staff') {
                $actor->forceFill(['role' => 'admin'])->save();
            }
        }

        return [$actor, $session->plainTextToken, in_array($state, ['deleted', 'revoked'], true) ? 401 : 403];
    }

    #[DataProvider('blockedActors')]
    public function test_every_donor_business_endpoint_rechecks_actor_state_without_side_effects(string $state): void
    {
        $owner = $this->donor();
        $opportunity = $this->opportunity();
        $item = $this->participation($owner, $opportunity);
        $notice = $this->notice($owner, $opportunity);
        [$actor, $token, $status] = $this->actor($state);
        foreach ($this->businessRequests($opportunity->id, $item->id, $notice->id) as [$method, $uri, $body]) {
            $this->requestAs($token, $method, $uri, $body)->assertStatus($status)->assertJsonMissingPath('token');
        }
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertSame('Synthetic', $owner->donorProfile->fresh()->first_name);
        foreach (['eligibility_assessments', 'donation_records', 'point_transactions', 'user_vouchers', 'email_changes', 'device_tokens', 'flowie_conversations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('proofs')->allFiles());
    }

    public function test_route_inventory_has_one_donor_boundary_and_keeps_staff_and_public_routes_separate(): void
    {
        $business = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();
            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/admin/') || ! in_array('auth:sanctum', $middleware, true)
                || in_array($uri, ['api/user', 'api/logout'], true)) {
                continue;
            }
            $guards = array_filter($middleware, fn ($value) => str_starts_with($value, EnsureDonorUser::class));
            $this->assertCount(1, $guards, $uri);
            $business[] = $route;
        }
        $this->assertCount(52, $business);
        $this->assertNull(app('router')->getRoutes()->getByAction(AuthController::class.'@login'));
    }

    public static function validDonors(): array
    {
        return ['donor A' => [false], 'donor B' => [true]];
    }

    #[DataProvider('validDonors')]
    public function test_active_verified_donors_keep_reads_writes_and_owner_isolation(bool $second): void
    {
        $a = $this->donor();
        $b = $this->donor();
        $owner = $second ? $b : $a;
        $other = $second ? $a : $b;
        $token = $owner->createToken('mobile')->plainTextToken;
        $opportunity = $this->opportunity();
        $foreignItem = $this->participation($other, $opportunity);
        $foreignNotice = $this->notice($other, $opportunity);
        $notice = $this->notice($owner, $opportunity);
        foreach (['user', 'donor-profile', 'donation-records', 'donation-summary', 'donation-opportunities', 'announcements',
            'donation-opportunities/'.$opportunity->id, 'announcements/'.$opportunity->id, 'eligibility-assessments',
            'eligibility-assessments/latest', 'notifications', 'notifications/unread-count', 'notifications/reminders',
            'notifications/'.$notice->id.'/announcement', 'points/summary', 'points/transactions', 'rewards', 'vouchers',
            'educational-articles', 'flowie/conversations', 'flowie/conversations/recently-deleted'] as $uri) {
            $this->requestAs($token, 'GET', $uri)->assertOk();
        }
        $this->requestAs($token, 'PUT', 'donor-profile', ['first_name' => 'Edited'])->assertOk();
        $this->requestAs($token, 'PUT', 'donor-profile/avatar', ['profile_avatar' => 'mascot_2'])->assertOk();
        $this->requestAs($token, 'POST', 'eligibility-assessments', ['answers' => $this->answers()])->assertCreated();
        $item = $this->requestAs($token, 'POST', 'donation-opportunities/'.$opportunity->id.'/join')->assertCreated()->json('participation.id');
        $this->requestAs($token, 'GET', 'donation-participations/'.$item)->assertOk()->assertJsonPath('participation.user_id', $owner->id);
        $this->requestAs($token, 'POST', 'donation-participations/'.$item.'/cancel')->assertOk();
        $item = $this->requestAs($token, 'POST', 'donation-opportunities/'.$opportunity->id.'/join')->assertCreated()->json('participation.id');
        $this->requestAs($token, 'POST', 'donation-participations/'.$item.'/proof', ['proof' => UploadedFile::fake()->image('proof.png')])->assertOk();
        $this->requestAs($token, 'GET', 'donation-participations/'.$item.'/proof')->assertOk();
        foreach ([['GET', 'donation-participations/'.$foreignItem->id], ['POST', 'donation-participations/'.$foreignItem->id.'/cancel'],
            ['POST', 'donation-participations/'.$foreignItem->id.'/proof'], ['GET', 'donation-participations/'.$foreignItem->id.'/proof'],
            ['POST', 'notifications/'.$foreignNotice->id.'/read'], ['GET', 'notifications/'.$foreignNotice->id.'/announcement']] as [$method, $uri]) {
            $this->requestAs($token, $method, $uri)->assertNotFound();
        }
        $this->requestAs($token, 'POST', 'notifications/'.$notice->id.'/read')->assertOk();
        $this->requestAs($token, 'POST', 'notifications/read-all')->assertOk();
        $device = ['token' => str_repeat('synthetic-device', 3), 'platform' => 'android', 'provider' => 'fcm'];
        $this->requestAs($token, 'POST', 'device-tokens', $device)->assertOk();
        $this->requestAs($token, 'POST', 'device-tokens/unregister', ['token' => $device['token']])->assertOk();
        $this->requestAs($token, 'POST', 'profile/change-email/request', ['current_password' => 'OldDonor123!', 'new_email' => 'new@example.test'])->assertAccepted();
        $this->requestAs($token, 'POST', 'profile/change-password', ['current_password' => 'OldDonor123!',
            'password' => 'newDonor123!', 'password_confirmation' => 'newDonor123!'])->assertOk();
        $this->assertSame('Synthetic', $other->donorProfile->fresh()->first_name);
        $this->assertSame('pending', $foreignItem->fresh()->status);
        $this->assertNull($foreignNotice->fresh()->read_at);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public static function retiredLoginActors(): array
    {
        return array_combine($states = ['donor', 'unverified', 'deactivated', 'deleted', 'admin', 'super_admin', 'unknown'], array_map(fn ($state) => [$state], $states));
    }

    #[DataProvider('retiredLoginActors')]
    public function test_legacy_password_login_cannot_issue_any_actor_a_token(string $state): void
    {
        $user = $this->donor();
        if ($state !== 'donor' && $state !== 'unknown') {
            [$user] = $this->actor($state);
        }
        $before = DB::table('personal_access_tokens')->count();
        $this->requestAs(null, 'POST', 'login', ['email' => $state === 'unknown' ? 'unknown@example.test' : $user->email,
            'password' => 'OldDonor123!'])->assertNotFound()->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', $before);
    }

    #[DataProvider('blockedActors')]
    public function test_mobile_identity_rejects_unusable_sessions_but_authenticated_logout_remains_safe(string $state): void
    {
        [$actor, $token, $status] = $this->actor($state);
        $this->requestAs($token, 'GET', 'user')->assertUnauthorized()->assertJsonMissingPath('email');
        $this->requestAs($token, 'POST', 'logout')->assertStatus($status === 401 ? 401 : 200);
        if ($status !== 401) {
            $this->assertSame(0, $actor->tokens()->count());
            $this->requestAs($token, 'GET', 'user')->assertUnauthorized();
        }
    }

    public static function staffRoles(): array
    {
        return ['Admin' => ['admin', false], 'Super Admin' => ['super_admin', false],
            'Initial Admin' => ['admin', true], 'Initial Super Admin' => ['super_admin', true]];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_cannot_change_credentials_or_email_through_donor_endpoints(string $role, bool $initial): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => $initial, 'password' => 'OldStaff123!']);
        $hash = $user->password;
        $token = $user->createToken('admin-web')->plainTextToken;
        $this->requestAs($token, 'POST', 'profile/change-password', ['current_password' => 'OldStaff123!',
            'password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertForbidden();
        $this->requestAs($token, 'POST', 'profile/change-email/request', ['current_password' => 'OldStaff123!',
            'new_email' => 'changed@example.test'])->assertForbidden();
        $this->requestAs(null, 'POST', 'forgot-password/request', ['email' => $user->email])->assertAccepted()
            ->assertExactJson(['message' => PasswordResetService::NOTICE, 'resend_after' => 60]);
        $row = DB::table('password_reset_codes')->first();
        app(PasswordResetService::class)->deliver($user->email, $row->request_id);
        Mail::assertNothingSent();
        $this->requestAs(null, 'POST', 'forgot-password/verify', ['email' => $user->email, 'code' => '123456'])->assertUnprocessable();
        $this->requestAs(null, 'POST', 'forgot-password/reset', ['email' => $user->email, 'reset_token' => str_repeat('a', 64),
            'password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($initial, $user->fresh()->must_change_password);
        $this->assertDatabaseCount('email_changes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function changedDonors(): array
    {
        $cases = [];
        foreach (['deactivated', 'unverified', 'staff', 'deleted'] as $state) {
            foreach (['delivery', 'verify', 'reset'] as $step) {
                $cases[$state.' '.$step] = [$state, $step];
            }
        }

        return $cases;
    }

    #[DataProvider('changedDonors')]
    public function test_generic_recovery_rechecks_state_and_cannot_restore_disabled_access(string $state, string $step): void
    {
        $user = $this->donor();
        $token = $user->createToken('before-deactivation')->plainTextToken;
        $hash = $user->password;
        $this->requestAs(null, 'POST', 'forgot-password/request', ['email' => $user->email])->assertAccepted();
        $row = DB::table('password_reset_codes')->first();
        $service = app(PasswordResetService::class);
        $code = '123456';
        $authorization = str_repeat('a', 64);
        if ($step !== 'delivery') {
            $service->deliver($user->email, $row->request_id);
            $code = Mail::sent(PasswordResetCodeMail::class)->last()->code;
            if ($step === 'reset') {
                $authorization = $service->verify($user->email, $code);
            }
        }
        $user->forceFill(match ($state) {
            'deactivated' => ['deactivated_at' => now()],
            'unverified' => ['email_verified_at' => null],
            'staff' => ['role' => 'admin'],
            'deleted' => ['deleted_at' => now()],
        })->save();
        if ($step === 'delivery') {
            $service->deliver($user->email, $row->request_id);
            Mail::assertNothingSent();
        }
        $this->requestAs(null, 'POST', 'forgot-password/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->requestAs(null, 'POST', 'forgot-password/reset', ['email' => $user->email, 'reset_token' => $authorization,
            'password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
        $this->requestAs(null, 'POST', 'login', ['email' => $user->email, 'password' => 'OldDonor123!'])->assertNotFound();
        $this->requestAs($token, 'GET', 'user')->assertUnauthorized();
    }

    public function test_preexisting_generic_staff_authorization_cannot_install_a_weak_password(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $hash = $user->password;
        $authorization = str_repeat('b', 64);
        DB::table('password_reset_codes')->insert(['email_hash' => hash('sha256', $user->email), 'request_id' => fake()->uuid(),
            'user_id' => $user->id, 'code_hash' => Hash::make(hash_hmac('sha256', '123456', config('app.key'))),
            'expires_at' => now()->addMinutes(15), 'attempt_count' => 0, 'verified_at' => now(),
            'reset_token_hash' => hash('sha256', $authorization), 'reset_token_expires_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now()]);
        $this->requestAs(null, 'POST', 'forgot-password/reset', ['email' => $user->email, 'reset_token' => $authorization,
            'password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_management_trigger_uses_staff_policy_and_atomic_audited_reset_revokes_sessions(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => true]);
        $oldToken = $admin->createToken('old-admin')->plainTextToken;
        $superToken = $super->createToken('admin-web')->plainTextToken;
        $this->requestAs($superToken, 'POST', 'admin/admins/'.$admin->id.'/send-password-reset')->assertAccepted();
        $job = Queue::pushed(SendPasswordResetCode::class)->last();
        $this->assertTrue($job->staffOnly);
        $job->handle(app(PasswordResetService::class));
        $code = Mail::sent(PasswordResetCodeMail::class)->last()->code;
        $this->requestAs(null, 'POST', 'forgot-password/verify', ['email' => $admin->email, 'code' => $code])->assertUnprocessable();
        $authorization = $this->requestAs(null, 'POST', 'admin/forgot-password/verify', ['email' => $admin->email, 'code' => $code])
            ->assertOk()->json('reset_token');
        $body = ['email' => $admin->email, 'reset_token' => $authorization, 'password' => 'weakpass', 'password_confirmation' => 'weakpass'];
        $this->requestAs(null, 'POST', 'admin/forgot-password/reset', $body)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->requestAs(null, 'POST', 'forgot-password/reset', $body)->assertUnprocessable();
        $this->requestAs(null, 'POST', 'admin/forgot-password/reset', [...$body, 'password' => 'NewStaffPass123!', 'password_confirmation' => 'NewStaffPass123!'])->assertOk();
        $this->assertTrue($admin->fresh()->must_change_password);
        $this->assertSame(0, $admin->tokens()->count());
        $this->requestAs($oldToken, 'GET', 'admin/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $super->id, 'action' => 'admin_password_reset_requested', 'target_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'admin_password_reset', 'target_id' => $admin->id]);
        $login = $this->requestAs(null, 'POST', 'admin/login', ['email' => $admin->email, 'password' => 'NewStaffPass123!'])->assertOk()->json('token');
        $this->requestAs($login, 'GET', 'admin/dashboard')->assertForbidden()->assertJsonPath('reason', 'password_change_required');
        $this->requestAs($login, 'POST', 'admin/change-initial-password', ['current_password' => 'NewStaffPass123!',
            'password' => 'weakpass', 'password_confirmation' => 'weakpass'])->assertUnprocessable();
    }

    public function test_staff_reset_audit_failure_rolls_back_password_sessions_and_authorization(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OldStaff123!']);
        $session = $user->createToken('staff-before-reset');
        $service = app(PasswordResetService::class);
        $service->requestCode($user->email, true);
        Queue::pushed(SendPasswordResetCode::class)->last()->handle($service);
        $code = Mail::sent(PasswordResetCodeMail::class)->last()->code;
        $token = $service->verify($user->email, $code, true);
        DB::statement("CREATE TRIGGER block_staff_reset_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'isolated audit rollback check'); END");
        try {
            $service->reset($user->email, $token, 'NewStaffPass123!', true);
            $this->fail('Audit failure must roll back the credential change.');
        } catch (QueryException) {
            $this->assertTrue(Hash::check('OldStaff123!', $user->fresh()->password));
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $session->accessToken->id]);
            $this->assertNull(DB::table('password_reset_codes')->first()->used_at);
            $this->assertSame(hash('sha256', $token), DB::table('password_reset_codes')->first()->reset_token_hash);
        } finally {
            DB::statement('DROP TRIGGER block_staff_reset_audit');
        }
        $service->reset($user->email, $token, 'NewStaffPass123!', true);
        $this->assertTrue(Hash::check('NewStaffPass123!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_password_reset', 'actor_user_id' => $user->id]);
    }

    public function test_locked_services_reject_a_stale_pre_deactivation_actor(): void
    {
        $user = $this->donor();
        $session = $user->createToken('mobile');
        $user->withAccessToken($session->accessToken);
        User::whereKey($user->id)->update(['deactivated_at' => now()]);
        foreach ([fn () => app(EligibilityCooldown::class)->submit($user, $this->answers()),
            fn () => app(AccountSettingsService::class)->password($user, 'OldDonor123!', 'newDonor123!')] as $write) {
            try {
                $write();
                $this->fail('A stale actor must not authorize a locked mutation.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('eligibility_assessments', 0);
        $this->assertTrue(Hash::check('OldDonor123!', $user->fresh()->password));
    }
}
