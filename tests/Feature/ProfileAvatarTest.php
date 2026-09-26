<?php

namespace Tests\Feature;

use App\Models\DonorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    private User $donor;

    private string $token;

    private array $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Build the old schema in isolated memory, insert a legacy donor, then apply only the additive migration.
        $paths = glob(database_path('migrations/*.php'));
        $avatar = array_values(array_filter($paths, fn ($path) => str_ends_with($path, '_add_profile_avatar_to_donor_profiles_table.php')))[0];
        $before = array_values(array_filter($paths, fn ($path) => $path !== $avatar));
        $this->artisan('migrate', ['--path' => $before, '--realpath' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->donor = User::factory()->create();
        $this->donor->donorProfile()->create($this->details());
        $this->legacy = (array) DB::table('donor_profiles')->first();
        $this->artisan('migrate', ['--path' => [$avatar], '--realpath' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->token = $this->donor->createToken('test')->plainTextToken;
    }

    private function details(): array
    {
        return ['first_name' => 'Avatar', 'middle_name' => null, 'last_name' => 'Donor', 'mobile_number' => '+639171234567', 'birth_date' => '2000-01-01', 'gender' => 'Female', 'blood_type' => 'O+'];
    }

    private function saveAvatar(array $data): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->putJson('/api/donor-profile/avatar', $data);
    }

    public function test_additive_migration_defaults_existing_donor_without_changing_old_fields(): void
    {
        $row = (array) DB::table('donor_profiles')->first();
        $this->assertSame('mascot_1', $row['profile_avatar']);
        unset($row['profile_avatar']);
        $this->assertSame($this->legacy, $row);
        $this->withToken($this->token)->getJson('/api/donor-profile')->assertOk()->assertJsonPath('donor_profile.profile_avatar', 'mascot_1');
    }

    public function test_new_verified_registration_uses_database_default(): void
    {
        $response = $this->registerVerified([
            ...$this->details(), 'email' => 'new-avatar@example.test', 'mobile_number' => '+639181234567',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'accepted_terms' => true, 'acknowledged_privacy' => true, 'acknowledged_prescreening' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('donor_profiles', ['user_id' => $response->json('user.id'), 'profile_avatar' => 'mascot_1']);
        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/donor-profile')->assertOk()->assertJsonPath('donor_profile.profile_avatar', 'mascot_1');
    }

    public function test_all_five_identifiers_save_and_normal_profile_edit_preserves_avatar(): void
    {
        foreach (range(1, 5) as $i) {
            $this->saveAvatar(['profile_avatar' => 'mascot_'.$i])->assertOk()->assertJsonPath('donor_profile.profile_avatar', 'mascot_'.$i);
        }
        $this->assertSame('mascot_5', $this->donor->donorProfile()->first()->profile_avatar);
        $this->withToken($this->token)->putJson('/api/donor-profile', ['first_name' => 'Updated'])->assertOk()->assertJsonPath('donor_profile.profile_avatar', 'mascot_5');
        $this->withToken($this->token)->putJson('/api/donor-profile', ['email' => 'forbidden@example.test'])->assertUnprocessable();
    }

    public function test_invalid_identifiers_paths_urls_and_unsupported_fields_are_rejected(): void
    {
        $before = DonorProfile::first()->getAttributes();
        foreach (['mascot_0', 'mascot_6', 'default', 'https://example.test/image.png', '../../images/avatar.png', 'LogoMascot.png', '__proto__', null, 5] as $value) {
            $this->saveAvatar(['profile_avatar' => $value])->assertUnprocessable()->assertJsonValidationErrors('profile_avatar');
        }
        $this->saveAvatar([])->assertUnprocessable();
        $this->saveAvatar(['profile_avatar' => 'mascot_2', 'email' => 'new@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, DonorProfile::first()->getAttributes());
    }

    public function test_ownership_and_guest_guards(): void
    {
        $this->putJson('/api/donor-profile/avatar', ['profile_avatar' => 'mascot_2'])->assertUnauthorized();
        $other = User::factory()->create();
        $other->donorProfile()->create([...$this->details(), 'mobile_number' => '+639181234567']);
        $this->saveAvatar(['profile_avatar' => 'mascot_2', 'user_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->saveAvatar(['profile_avatar' => 'mascot_3'])->assertOk();
        $this->assertSame('mascot_1', $other->donorProfile()->first()->profile_avatar);
        $this->assertSame('mascot_3', $this->donor->donorProfile()->first()->profile_avatar);
    }

    public function test_missing_profile_is_not_created_by_avatar_endpoint(): void
    {
        $other = User::factory()->create();
        $this->token = $other->createToken('no-profile')->plainTextToken;
        $this->saveAvatar(['profile_avatar' => 'mascot_2'])->assertNotFound();
        $this->assertDatabaseCount('donor_profiles',1);
    }
}
