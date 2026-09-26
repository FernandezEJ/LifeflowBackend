<?php

namespace Tests\Feature;

use App\Models\DonorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DonorProfileTest extends TestCase
{
    // ========================================
    // ISOLATED DATABASE
    // Ordinary migrations run only in a new SQLite memory database for each test.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    // ========================================
    // FULL REGISTRATION AND AUTH REGRESSION
    // Proves registration links exactly one profile, hashes passwords, and returns
    // a working token. Login and logout also work for the newly registered account.
    // ========================================
    public function test_full_registration_and_authentication_flow(): void
    {
        $response = $this->registerVerified($this->payload())->assertCreated()
            ->assertJsonPath('user.name', 'Juan Santos Dela Cruz')
            ->assertJsonPath('donor_profile.mobile_number', '+639171234567')
            ->assertJsonPath('donor_profile.birth_date', '2004-09-17')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $user = User::firstOrFail();
        $this->assertSame($user->id, $user->donorProfile->user->id);
        $this->assertSame($user->id, $response->json('donor_profile.user_id'));
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString('password123', $response->getContent());
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->withToken($token)->getJson('/api/donor-profile')->assertOk()
            ->assertJsonPath('user.id', $user->id);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'juan@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/donor-profile')->assertUnauthorized();
    }

    // ========================================
    // DUPLICATE CONTACT DETAILS
    // Local and international representations share one unique mobile number.
    // ========================================
    public function test_duplicate_email_and_normalized_mobile_are_rejected(): void
    {
        $this->registerVerified($this->payload())->assertCreated();
        $this->registerVerified($this->payload(['mobile_number' => '09181234567']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->registerVerified($this->payload([
            'email' => 'other@example.com', 'mobile_number' => '+63 (917) 123-4567',
        ]))->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donor_profiles', 1);
    }

    // ========================================
    // VALIDATION CASES
    // Exercises required names, mobile format, calendar dates, and allowed values.
    // ========================================
    public static function invalidFields(): array
    {
        return [
            'missing first name' => ['first_name', null],
            'missing last name' => ['last_name', null],
            'long first name' => ['first_name', str_repeat('a', 81)],
            'invalid mobile' => ['mobile_number', '08171234567'],
            'numeric mobile' => ['mobile_number', 9171234567],
            'invalid blood type' => ['blood_type', 'C+'],
            'invalid gender' => ['gender', 'Other'],
            'future birthday' => ['birth_date', '2999-01-01'],
            'invalid date' => ['birth_date', '2004-02-30'],
            'UI date format' => ['birth_date', '09/17/2004'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_registration_validates_profile_fields(string $field, mixed $value): void
    {
        $this->registerVerified($this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('donor_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_consents', 0);
    }

    // ========================================
    // OPTIONAL MIDDLE NAME
    // Accepts international numbers and omitted middle names without extra spaces.
    // ========================================
    public function test_middle_name_is_optional_and_today_is_not_a_past_date(): void
    {
        $data = $this->payload(['mobile_number' => '+639171234567']);
        unset($data['middle_name']);
        $this->registerVerified(array_replace($data, ['birth_date' => now()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->registerVerified($data)->assertCreated()
            ->assertJsonPath('user.name', 'Juan Dela Cruz');
        $this->assertNull(DonorProfile::firstOrFail()->middle_name);
    }

    // ========================================
    // PRIVATE PROFILE ACCESS
    // Both profile endpoints require authentication and ignore query-string identities.
    // ========================================
    public function test_profile_access_uses_only_the_token_owner(): void
    {
        $this->getJson('/api/donor-profile')->assertUnauthorized();
        $this->putJson('/api/donor-profile', [])->assertUnauthorized();
        $token = $this->registerToken();
        $other = User::factory()->create();
        $this->withToken($token)->getJson('/api/donor-profile?user_id='.$other->id)->assertOk()
            ->assertJsonPath('user.email', 'juan@example.com')
            ->assertJsonPath('donor_profile.birth_date', '2004-09-17')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token')
            ->assertJsonMissingPath('user.tokens');
    }

    // ========================================
    // SAFE PROFILE EDITS
    // Updates all permitted fields, preserves email verification, and synchronizes names.
    // Unrecognized password/name fields cannot modify the account.
    // ========================================
    public function test_profile_update_synchronizes_account_and_preserves_password(): void
    {
        $token = $this->registerToken();
        $user = User::firstOrFail();
        $password = $user->password;
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->withToken($token)->putJson('/api/donor-profile', [
            'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Reyes',
            'mobile_number' => '09181234567',
            'birth_date' => '2000-01-02', 'gender' => 'Female', 'blood_type' => 'AB-',
            'password' => 'should-not-change', 'name' => 'Untrusted name',
        ])->assertOk()->assertJsonPath('user.name', 'Maria Reyes')
            ->assertJsonPath('user.email', 'juan@example.com')
            ->assertJsonPath('user.email_verified_at', $user->email_verified_at->toJSON())
            ->assertJsonPath('donor_profile.mobile_number', '+639181234567')
            ->assertJsonPath('donor_profile.birth_date', '2000-01-02')
            ->assertJsonPath('donor_profile.gender', 'Female')
            ->assertJsonPath('donor_profile.blood_type', 'AB-')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        $this->assertSame($password, $user->fresh()->password);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/donor-profile', ['first_name' => 'Ana'])
            ->assertOk()->assertJsonPath('user.name', 'Ana Reyes');
        $this->assertDatabaseCount('donor_profiles', 1);
    }

    // ========================================
    // UPDATE UNIQUENESS AND OWNERSHIP
    // Keeps unchanged contact values valid but rejects another donor's contacts or identity.
    // ========================================
    public function test_update_rejects_duplicate_contacts_and_ownership_changes(): void
    {
        $token = $this->registerToken();
        $other = $this->registerVerified($this->payload([
            'email' => 'other@example.com', 'mobile_number' => '09181234567',
        ]))->assertCreated()->json('user.id');
        $this->withToken($token)->putJson('/api/donor-profile', [
            'mobile_number' => '09171234567',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/donor-profile', [
            'email' => 'other@example.com', 'mobile_number' => '+639181234567',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile_number']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/donor-profile', ['user_id' => $other])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame('juan@example.com', DonorProfile::where('mobile_number', '+639171234567')->firstOrFail()->user->email);
        $this->assertDatabaseCount('donor_profiles', 2);
    }

    // ========================================
    // UPDATE VALIDATION
    // Updates enforce the same profile rules as registration before saving anything.
    // ========================================
    #[DataProvider('invalidFields')]
    public function test_updates_validate_profile_fields(string $field, mixed $value): void
    {
        $token = $this->registerToken();
        $before = DonorProfile::firstOrFail()->getAttributes();
        $this->withToken($token)->putJson('/api/donor-profile', [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, DonorProfile::firstOrFail()->getAttributes());
    }

    // ========================================
    // OLDER ACCOUNTS
    // Missing profiles produce clear 404 responses without inventing personal information.
    // ========================================
    public function test_older_account_without_profile_gets_clear_not_found_responses(): void
    {
        $token = User::factory()->create()->createToken('legacy')->plainTextToken;
        $this->withToken($token)->getJson('/api/donor-profile')->assertNotFound()
            ->assertExactJson(['message' => 'Donor profile not found.']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/donor-profile', ['first_name' => 'Juan'])->assertNotFound();
        $this->assertDatabaseCount('donor_profiles', 0);
    }

    // ========================================
    // REGISTRATION ROLLBACK
    // A token failure after user/profile creation must undo both records and hide errors.
    // ========================================
    public function test_registration_rolls_back_when_token_creation_fails(): void
    {
        PersonalAccessToken::creating(function () {
            throw new RuntimeException('Internal token failure');
        });
        try {
            $this->registerVerified($this->payload())->assertStatus(500)
                ->assertExactJson(['message' => 'Registration could not be completed. Please try again.']);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('donor_profiles', 0);
            $this->assertDatabaseCount('personal_access_tokens', 0);
        } finally {
            PersonalAccessToken::flushEventListeners();
        }
    }

    // ========================================
    // UPDATE ROLLBACK
    // A profile save failure restores the account email and name as well as the profile.
    // ========================================
    public function test_update_rolls_back_both_records_on_failure(): void
    {
        $token = $this->registerToken();
        DonorProfile::updating(function () {
            throw new RuntimeException('Internal profile failure');
        });
        try {
            $this->withToken($token)->putJson('/api/donor-profile', [
                'first_name' => 'Changed',
            ])->assertStatus(500)->assertExactJson(['message' => 'Profile could not be updated. Please try again.']);
            $this->assertSame('Juan Santos Dela Cruz', User::firstOrFail()->name);
            $this->assertSame('juan@example.com', User::firstOrFail()->email);
            $this->assertSame('Juan', DonorProfile::firstOrFail()->first_name);
        } finally {
            DonorProfile::flushEventListeners();
        }
    }

    // ========================================
    // SHARED TEST INPUT
    // Creates real API tokens and donor details only inside the isolated test database.
    // ========================================
    public function test_profile_edit_cannot_change_email_or_verification(): void
    {
        $token = $this->registerToken();
        $before = User::firstOrFail()->getAttributes();
        $profile = DonorProfile::firstOrFail()->getAttributes();
        foreach (['changed@example.com', 'juan@example.com', null, ''] as $email) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->putJson('/api/donor-profile', [
                'email' => $email, 'first_name' => 'Changed', 'email_verified_at' => null,
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertSame($before, User::firstOrFail()->getAttributes());
            $this->assertSame($profile, DonorProfile::firstOrFail()->getAttributes());
        }
        $this->withToken($token)->putJson('/api/donor-profile', ['email_verified_at' => null])->assertOk();
        $this->assertSame($before['email_verified_at'], User::firstOrFail()->getRawOriginal('email_verified_at'));
    }

    private function registerToken(): string
    {
        return $this->registerVerified($this->payload())->assertCreated()->json('token');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com', 'mobile_number' => '09171234567',
            'password' => 'password123', 'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
            'password_confirmation' => 'password123',
            'birth_date' => '2004-09-17', 'gender' => 'Male', 'blood_type' => 'O+',
        ], $overrides);
    }
}
