<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DonorLoginService;
use App\Services\SessionTokenPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionExpirationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('proofs');
        Storage::fake('public');
    }

    private function getWith(string $token, string $path): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/'.$path);
    }

    public static function staffRoles(): array
    {
        return [['admin', false], ['super_admin', false], ['admin', true], ['super_admin', true]];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_login_issues_absolute_twelve_hour_session(string $role, bool $initial): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'StaffPass123!', 'must_change_password' => $initial]);
        $token = $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'StaffPass123!'])
            ->assertOk()->json('token');
        $stored = PersonalAccessToken::findToken($token);
        $this->assertSame(['*'], $stored->abilities);
        $this->assertTrue($stored->expires_at->equalTo(now()->addHours(12)));
        $this->travel(12 * 3600 - 1)->seconds();
        $this->getWith($token, 'admin/me')->assertOk()->assertJsonPath('user.must_change_password', $initial);
        $this->travel(1)->seconds();
        $this->getWith($token, 'admin/me')->assertUnauthorized();
        $this->getWith($token, 'admin/dashboard')->assertUnauthorized();
    }

    public function test_donor_otp_and_registration_issue_thirty_day_sessions(): void
    {
        $donor = User::factory()->create();
        $token = $this->loginVerifiedDonor($donor)->assertOk()->json('token');
        $stored = PersonalAccessToken::findToken($token);
        $this->assertTrue($stored->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame(['*'], $stored->abilities);
        $registration = $this->registerVerified(['first_name' => 'Synthetic', 'last_name' => 'Donor',
            'mobile_number' => '09179999999', 'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+',
            'email' => 'registered@example.test', 'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true])
            ->assertCreated();
        $registered = PersonalAccessToken::findToken($registration->json('token'));
        $this->assertTrue($registered->expires_at->equalTo(now()->addDays(30)));
        $this->travel(30 * 86400 - 1)->seconds();
        $this->getWith($token, 'user')->assertOk();
        $this->travel(1)->seconds();
        $this->getWith($token, 'user')->assertUnauthorized();
        $this->getWith($registration->json('token'), 'user')->assertUnauthorized();
    }

    public static function legacyAges(): array
    {
        return [
            'admin just before' => ['admin', 'admin-web', 43199, 200],
            'admin exact boundary' => ['admin', 'admin-web', 43200, 401],
            'super admin after' => ['super_admin', 'mobile', 43201, 401],
            'donor just before' => ['donor', 'mobile', 2591999, 200],
            'donor boundary' => ['donor', 'mobile', 2592000, 401],
            'donor after' => ['donor', 'mobile', 2592001, 401],
            'staff context retained on donor' => ['donor', 'admin-web', 43200, 401],
            'old staff arbitrary name' => ['admin', 'legacy-session', 43200, 401],
        ];
    }

    #[DataProvider('legacyAges')]
    public function test_null_expiry_tokens_use_original_issuance_age(string $role, string $name, int $seconds, int $status): void
    {
        $user = User::factory()->create(['role' => $role]);
        $token = $user->createToken($name);
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => now()->subSeconds($seconds)]);
        $this->getWith($token->plainTextToken, $role === 'donor' ? 'user' : 'admin/me')->assertStatus($status);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id, 'expires_at' => null]);
    }

    public static function unsafeTimestamps(): array
    {
        return [
            'missing created' => [null, null],
            'empty created' => ['', null],
            'invalid created' => ['not-a-date', null],
            'impossible created' => ['2026-02-30 00:00:00', null],
            'empty expiry' => ['2026-10-28 00:00:00', ''],
            'invalid expiry' => ['2026-10-28 00:00:00', 'not-a-date'],
            'invalid future-like expiry' => ['2026-10-28 00:00:00', '9999-99-99 99:99:99'],
        ];
    }

    #[DataProvider('unsafeTimestamps')]
    public function test_invalid_or_missing_timestamp_fails_closed_without_a_server_error(?string $created, ?string $expires): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => $created, 'expires_at' => $expires]);
        $this->getWith($token->plainTextToken, 'user')->assertUnauthorized();
        $this->getWith($token->plainTextToken, 'donor-profile')->assertUnauthorized();
    }

    public function test_explicit_expiry_is_stricter_and_cannot_extend_the_role_maximum(): void
    {
        foreach (['admin' => 43200, 'donor' => 2592000] as $role => $seconds) {
            $user = User::factory()->create(['role' => $role]);
            $name = $role === 'donor' ? 'mobile' : 'admin-web';
            $path = $role === 'donor' ? 'user' : 'admin/me';
            $short = $user->createToken($name, ['*'], now()->addSeconds(10));
            $this->getWith($short->plainTextToken, $path)->assertOk();
            $this->travel(10)->seconds();
            $this->getWith($short->plainTextToken, $path)->assertUnauthorized();
            $long = $user->createToken($name, ['*'], now()->addYear());
            DB::table('personal_access_tokens')->where('id', $long->accessToken->id)->update(['created_at' => now()->subSeconds($seconds)]);
            $this->getWith($long->plainTextToken, $path)->assertUnauthorized();
        }
    }

    public function test_future_issuance_is_rejected_and_last_use_cannot_slide_a_legacy_lease(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('admin-web');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => now()->addSecond()]);
        $this->getWith($token->plainTextToken, 'admin/me')->assertUnauthorized();
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => now()->subSeconds(43199)]);
        $this->getWith($token->plainTextToken, 'admin/me')->assertOk();
        $this->travel(1)->seconds();
        $this->getWith($token->plainTextToken, 'admin/me')->assertUnauthorized();
    }

    public static function roleChanges(): array
    {
        return [['admin', 'donor'], ['donor', 'admin'], ['super_admin', 'donor'], ['super_admin', 'admin']];
    }

    #[DataProvider('roleChanges')]
    public function test_role_conversion_tightens_existing_tokens_without_renewal(string $before, string $after): void
    {
        $user = User::factory()->create(['role' => $before]);
        $token = $user->createToken('mobile');
        $created = now()->subHours(10);
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => $created]);
        $user->forceFill(['role' => $after])->save();
        $stored = PersonalAccessToken::findToken($token->plainTextToken);
        $this->assertTrue($stored->expires_at->equalTo($created->copy()->addHours(12)));
        $this->travel(2)->hours();
        $this->getWith($token->plainTextToken, $after === 'donor' ? 'user' : 'admin/me')->assertUnauthorized();
    }

    public static function expiredActors(): array
    {
        return [['admin'], ['super_admin'], ['donor']];
    }

    #[DataProvider('expiredActors')]
    public function test_expired_session_is_denied_on_every_protected_api_route_including_logout(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $token = $user->createToken($role === 'donor' ? 'mobile' : 'admin-web');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['created_at' => now()->subDays(31)]);
        $tested = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $this->app['auth']->forgetGuards();
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $method = $route->methods()[0];
            $this->withToken($token->plainTextToken)->json($method, '/'.$uri)->assertUnauthorized();
            $tested++;
        }
        $this->assertSame(102, $tested);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_public_challenges_remain_independent_of_an_expired_bearer(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['*'], now()->subSecond())->plainTextToken;
        $this->withToken($token)->postJson('/api/auth/request-login-code', ['identifier' => 'synthetic@example.test'])->assertAccepted();
        $this->withToken($token)->postJson('/api/admin/forgot-password/request', ['email' => 'staff@example.test'])->assertAccepted();
        $this->assertSame(600, DonorLoginService::EXPIRY_SECONDS);
        $this->assertSame(60, DonorLoginService::RESEND_SECONDS);
    }

    public function test_role_change_does_not_extend_a_preexisting_short_expiry(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $cutoff = now()->addMinute();
        $token = $user->createToken('admin-web', ['*'], $cutoff);
        $user->forceFill(['role' => 'donor'])->save();
        $this->assertTrue(PersonalAccessToken::findToken($token->plainTextToken)->expires_at->equalTo($cutoff));
        $this->assertSame(43200, SessionTokenPolicy::STAFF_SECONDS);
        $this->assertSame(2592000, SessionTokenPolicy::DONOR_SECONDS);
    }
}
