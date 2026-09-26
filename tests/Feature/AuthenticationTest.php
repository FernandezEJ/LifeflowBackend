<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    // ========================================
    // ISOLATED TEST DATABASE
    // Runs ordinary migrations only in SQLite memory, never in LifeFlow MySQL.
    // Each test starts with a new application and an empty in-memory database.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    // ========================================
    // REGISTRATION AND PASSWORD SECURITY
    // Checks the account, hashed password, hidden fields, and usable bearer token.
    // ========================================
    public function test_registration_creates_a_user_and_usable_token(): void
    {
        $response = $this->registerVerified($this->registrationData());

        $response->assertCreated()->assertJsonPath('message', 'Registration successful')
            ->assertJsonPath('user.email', 'testdonor@example.com')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');

        $user = User::where('email', 'testdonor@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString('password123', $response->getContent());
        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    // ========================================
    // DUPLICATE REGISTRATION
    // Rejects reused email addresses without issuing another token or account.
    // ========================================
    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'testdonor@example.com']);
        $this->registerVerified($this->registrationData())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ========================================
    // REGISTRATION VALIDATION
    // Requires valid account details and a matching password of at least 8 characters.
    // ========================================
    public function test_registration_rejects_invalid_or_missing_fields(): void
    {
        $this->registerVerified([])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password', 'password_confirmation']);
        $this->registerVerified(array_replace($this->registrationData(), [
            'email' => 'invalid', 'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->registerVerified(array_replace($this->registrationData(), [
            'password_confirmation' => 'different-password',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    // ========================================
    // SUCCESSFUL LOGIN
    // Returns a working token while keeping passwords and remember tokens private.
    // ========================================
    public function test_login_returns_a_usable_token_without_sensitive_fields(): void
    {
        $user = User::factory()->create(['email' => 'testdonor@example.com', 'password' => 'password123']);
        $response = $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'password123',
        ]);
        $response->assertOk()->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString('password123', $response->getContent());
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    // ========================================
    // FAILED LOGIN
    // Wrong passwords and unknown accounts receive the same JSON error and no token.
    // ========================================
    public function test_incorrect_credentials_are_rejected(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'incorrect'])
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid email or password.']);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ========================================
    // LOGIN VALIDATION
    // Missing or malformed credentials return Laravel validation errors.
    // ========================================
    public function test_login_requires_valid_fields(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/api/login', ['email' => 'invalid', 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    // ========================================
    // AUTHENTICATED USER IDENTITY
    // The bearer token determines the user even if another user ID is supplied.
    // ========================================
    public function test_current_user_is_identified_only_by_the_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/user?user_id='.$other->id);
        $response->assertOk()->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    // ========================================
    // UNAUTHENTICATED REQUESTS
    // Protected routes return JSON 401 responses even without an Accept header.
    // ========================================
    public function test_protected_routes_require_authentication(): void
    {
        $this->get('/api/user')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->post('/api/logout')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->withToken('invalid-token')->getJson('/api/user')->assertUnauthorized();
    }

    // ========================================
    // LOGOUT AND REVOCATION
    // Re-authenticates every request to prove deletion blocks reuse while other tokens work.
    // ========================================
    public function test_logout_revokes_only_the_current_token_and_blocks_reuse(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current-device');
        $other = $user->createToken('other-device');

        $this->withToken($current->plainTextToken)->postJson('/api/logout')->assertOk()
            ->assertExactJson(['message' => 'Logout successful']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/user')->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    // ========================================
    // SAMPLE REGISTRATION DATA
    // Reuses the requested test account only inside the isolated test database.
    // ========================================
    public function test_login_is_rate_limited_without_issuing_a_token_and_recovers(): void
    {
        $user = User::factory()->create(['email' => 'limited@example.com', 'password' => 'password123']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => strtoupper($user->email), 'password' => 'password123'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->travel(61)->seconds();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_ip_limit_cannot_be_bypassed_by_rotating_email_addresses(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/login', ['email' => 'unknown'.$attempt.'@example.com', 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'another@example.com', 'password' => 'incorrect'])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_non_string_email_without_server_error(): void
    {
        $this->postJson('/api/login', ['email' => ['unexpected'], 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    private function registrationData(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Donor',
            'mobile_number' => '09171234567',
            'birth_date' => '2004-09-17',
            'gender' => 'Male',
            'blood_type' => 'O+',
            'email' => 'testdonor@example.com',
            'password' => 'password123',
            'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
            'password_confirmation' => 'password123',
        ];
    }
}
