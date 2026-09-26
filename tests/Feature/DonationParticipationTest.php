<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonationParticipationTest extends TestCase
{
    // ========================================
    // ISOLATED DATABASE AND PRIVATE FILES
    // Uses SQLite memory and a fake local disk, never real donor data or proof.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        Storage::fake('proofs');
    }

    // ========================================
    // PUBLICATION AND EXPIRATION
    // Only active published announcements are visible, without deleting expired data.
    // ========================================
    public function test_active_board_and_details_exclude_inactive_posts(): void
    {
        $this->signIn();
        $active = $this->opportunity();
        $expired = $this->opportunity(['expires_at' => now()->subSecond()]);
        foreach (['draft', 'cancelled', 'expired'] as $status) {
            $this->opportunity(['status' => $status]);
        }
        $this->opportunity(['published_at' => now()->addDay()]);
        $this->getJson('/api/donation-opportunities')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/donation-opportunities/'.$active->id)->assertOk();
        $this->getJson('/api/donation-opportunities/'.$expired->id)->assertNotFound();
        $this->postJson('/api/donation-opportunities/'.$expired->id.'/join')->assertNotFound();
        $this->assertDatabaseCount('donation_opportunities', 6);
    }

    // ========================================
    // JOIN, DUPLICATES, AND EXPIRED ACTIVITY
    // Joins create pending activity only, and expiry never hides owned activity.
    // ========================================
    public function test_join_duplicate_and_expiry(): void
    {
        $opportunity = $this->opportunity();
        $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->assertUnauthorized();
        $user = $this->signIn();
        $joined = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->assertCreated()
            ->assertJsonPath('participation.status', 'pending')->assertJsonPath('participation.user_id', $user->id)
            ->assertJsonPath('participation.opportunity.points_reward', 300)->json('participation.id');
        $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->assertConflict();
        $opportunity->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/donation-participations/'.$joined)->assertOk();
        $this->getJson('/api/donation-participations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0);
        $this->assertDatabaseCount('donation_records', 0);
    }

    // ========================================
    // CANCELLATION AND REJOIN
    // Cancelled rows stay in history; a new join is allowed while the opportunity is active.
    // ========================================
    public function test_cancel_pending_and_rejoin(): void
    {
        $this->signIn();
        $opportunity = $this->opportunity();
        $id = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->json('participation.id');
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertOk()->assertJsonPath('participation.status', 'cancelled');
        $this->assertNotNull(DonationParticipation::findOrFail($id)->cancelled_at);
        $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->assertCreated();
        $this->assertDatabaseCount('donation_participations', 2);
    }

    // ========================================
    // EXTERNAL PROOF METADATA QUEUE
    // Private uploads queue verification without completing a donation or granting points.
    // ========================================
    public function test_valid_proof_queues_verification(): void
    {
        // Freeze server time so submitted metadata cannot choose the timestamp.
        $this->freezeTime();
        $this->signIn();
        $opportunity = $this->opportunity();
        $id = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->json('participation.id');
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())
            ->assertOk()->assertJsonPath('participation.status', 'for_verification')->assertJsonMissingPath('participation.proof_storage_path')->assertJsonMissingPath('participation.proof_url')->assertJsonMissingPath('participation.proof_path');
        $row = DonationParticipation::findOrFail($id);
        Storage::disk('proofs')->assertExists($row->proof_path);
        $this->assertSame(1024, $row->proof_size);
        $this->assertSame('application/pdf', $row->proof_mime_type);
        $this->getJson('/api/donation-participations/'.$id.'/proof')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotNull($row->proof_uploaded_at);
        $this->assertSame(now()->toDateTimeString(), $row->proof_uploaded_at->toDateTimeString());
        $this->assertDatabaseCount('donation_records', 0);
        $this->assertNull($row->verified_at);
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertConflict();
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertConflict();
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0);
    }

    // ========================================
    // TERMINAL STATE AND FILE VALIDATION
    // Only pending participation accepts proof or cancellation.
    // ========================================
    public static function closedStates(): array
    {
        return [['for_verification'], ['completed'], ['rejected'], ['cancelled']];
    }

    #[DataProvider('closedStates')]
    public function test_closed_states_reject_donor_changes(string $status): void
    {
        $user = $this->signIn();
        $item = $user->donationParticipations()->make();
        $item->donation_opportunity_id = $this->opportunity()->id;
        $item->joined_at = now();
        $item->status = $status;
        $item->save();
        $this->postJson('/api/donation-participations/'.$item->id.'/cancel')->assertConflict();
        $this->postJson('/api/donation-participations/'.$item->id.'/proof', $this->proofFile())->assertConflict();
    }

    public function test_proof_validation_and_cross_user_access(): void
    {
        $this->signIn();
        $opportunity = $this->opportunity();
        $id = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->json('participation.id');
        $this->postJson('/api/donation-participations/'.$id.'/proof', [])->assertUnprocessable();
        $this->postJson('/api/donation-participations/'.$id.'/proof', ['proof' => UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream')])->assertUnprocessable();
        $this->postJson('/api/donation-participations/'.$id.'/proof', ['proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->signIn();
        $this->getJson('/api/donation-participations/'.$id)->assertNotFound();
        $this->getJson('/api/donation-participations')->assertJsonPath('total', 0);
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertNotFound();
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertNotFound();
        $this->assertCount(0, Storage::disk('proofs')->allFiles());
    }

    // ========================================
    // SPOOFED LIFECYCLE FIELDS
    // No donor input can set ownership, verification status, or reward data.
    // ========================================
    public function test_privileged_fields_are_rejected(): void
    {
        $this->signIn();
        $id = $this->opportunity()->id;
        foreach (['user_id' => 999, 'status' => 'completed', 'verified_at' => now()->toISOString(), 'points_reward' => 999] as $field => $value) {
            $this->postJson('/api/donation-opportunities/'.$id.'/join', [$field => $value])->assertUnprocessable();
        }
        $this->assertDatabaseCount('donation_participations', 0);
    }

    // ========================================
    // TRUSTED TEST FIXTURES
    // No admin publishing endpoint or fake production announcements are created.
    // ========================================
    private function proofFile(): array
    {
        return ['proof' => UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf')];
    }

    public function test_proof_requires_sanctum_authentication(): void
    {
        $this->postJson('/api/donation-participations/1/proof', [])->assertUnauthorized();
        $this->assertDatabaseCount('donation_participations', 0);
    }

    public static function invalidProofMetadata(): array
    {
        return [
            ['proof_storage_path', '../foreign.pdf'],
            ['proof_path', '../foreign.pdf'],
            ['proof_url', 'https://example.test/proof.pdf'],
            ['proof_mime_type', 'application/pdf'],
            ['proof_size', '1'],
            ['proof_original_name', 'foreign.pdf'],
            ['proof_uploaded_at', '2026-01-01'],
        ];
    }

    #[DataProvider('invalidProofMetadata')]
    public function test_client_metadata_returns_422(string $field, string $value): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/'.$this->opportunity()->id.'/join')->json('participation.id');

        $this->postJson('/api/donation-participations/'.$id.'/proof', array_replace($this->proofFile(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('donation_participations', ['id' => $id, 'status' => 'pending', 'proof_uploaded_at' => null]);
        $this->assertDatabaseCount('donation_records', 0);
    }

    public function test_invalid_metadata_and_server_owned_timestamp(): void
    {
        $this->signIn();
        $opportunity = $this->opportunity();
        $id = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->json('participation.id');
        foreach (['proof_url' => 'http://example.com/file', 'proof_storage_path' => '../file',
            'proof_original_name' => '../file.pdf', 'proof_mime_type' => 'application/x-php',
            'proof_uploaded_at' => '2026-01-01', 'status' => 'completed', 'user_id' => 999] as $key => $value) {
            $this->postJson('/api/donation-participations/'.$id.'/proof', array_replace($this->proofFile(), [$key => $value]))->assertUnprocessable();
        }
        $this->assertSame('pending', DonationParticipation::findOrFail($id)->status);
        $this->assertCount(0, Storage::disk('proofs')->allFiles());
    }

    public static function privateProofTypes(): array
    {
        return [['jpg', 'image/jpeg'], ['png', 'image/png'], ['pdf', 'application/pdf']];
    }

    #[DataProvider('privateProofTypes')]
    public function test_allowed_types_are_private_and_download_requires_owner(string $extension, string $mime): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $this->postJson('/api/donation-participations/'.$id.'/proof', ['proof' => UploadedFile::fake()->create('donor-file.'.$extension, 5120, $mime)])
            ->assertOk()->assertJsonPath('participation.proof_size', 5242880)->assertJsonMissingPath('participation.proof_path')
            ->assertJsonMissingPath('participation.proof_url')->assertJsonMissingPath('participation.proof_storage_path');
        $row = DonationParticipation::findOrFail($id);
        $this->assertStringEndsWith('.'.$extension, $row->proof_path);
        $this->assertStringNotContainsString('donor-file', $row->proof_path);
        Storage::disk('proofs')->assertExists($row->proof_path);
        $this->getJson('/api/donation-participations/'.$id.'/proof')->assertOk()->assertHeader('Content-Type', $mime);
        $this->app['auth']->forgetGuards();
        $this->signIn();
        $this->getJson('/api/donation-participations/'.$id.'/proof')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/donation-participations/'.$id.'/proof')->assertUnauthorized();
    }

    public function test_file_content_is_checked_and_missing_legacy_or_traversal_paths_are_not_served(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $route = '/api/donation-participations/'.$id.'/proof';
        $fake = UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "not an image";');
        $this->postJson($route, ['proof' => new UploadedFile($fake->getPathname(), 'fake.jpg', 'image/jpeg', null, true)])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->getJson($route)->assertNotFound();
        $row = DonationParticipation::findOrFail($id);
        $row->forceFill(['proof_url' => 'https://example.test/legacy', 'proof_storage_path' => 'legacy.pdf', 'proof_path' => '../secret.txt'])->save();
        $this->getJson($route)->assertNotFound();
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertCount(0, Storage::disk('proofs')->allFiles());
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $realPdf = UploadedFile::fake()->createWithContent('proof.pdf', $pdf);
        $this->postJson($route, ['proof' => new UploadedFile($realPdf->getPathname(), 'proof.pdf', 'application/pdf', null, true)])->assertOk();
        $this->assertSame($pdf, Storage::disk('proofs')->get($row->fresh()->proof_path));
    }

    public function test_database_failure_removes_only_new_file_and_leaves_pending_state(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        Storage::disk('proofs')->put('unrelated.txt', 'preserve');
        DonationParticipation::saving(function (DonationParticipation $row) {
            if ($row->status === 'for_verification') {
                throw new \RuntimeException('Simulated database failure');
            }
        });
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertStatus(500);
        $this->assertDatabaseHas('donation_participations', ['id' => $id, 'status' => 'pending', 'proof_path' => null, 'proof_uploaded_at' => null]);
        $this->assertSame(['unrelated.txt'], Storage::disk('proofs')->allFiles());
    }

    public function test_failed_storage_write_preserves_pending_state(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('proofs')->andReturn($disk);
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertStatus(500);
        $this->assertDatabaseHas('donation_participations', ['id' => $id, 'status' => 'pending', 'proof_path' => null]);
    }

    public function test_revision_resubmission_replaces_private_proof_without_awards(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $url = '/api/donation-participations/'.$id.'/proof';
        $this->postJson($url, $this->proofFile())->assertOk();
        $row = DonationParticipation::findOrFail($id);
        $oldPath = $row->proof_path;
        $row->forceFill(['status' => 'needs_revision', 'revision_reason' => 'Please upload a clearer image.'])->save();
        $this->getJson('/api/donation-participations?status=needs_revision')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.revision_reason', 'Please upload a clearer image.');
        $this->postJson('/api/donation-participations/'.$id.'/cancel')->assertConflict();
        $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertConflict()->assertJsonPath('reason', 'active_participation_exists');
        $this->postJson($url, ['proof' => UploadedFile::fake()->create('clear.png', 2, 'image/png')])
            ->assertOk()->assertJsonPath('participation.status', 'for_verification')
            ->assertJsonPath('participation.revision_reason', null)->assertJsonPath('participation.proof_original_name', 'clear.png')
            ->assertJsonPath('participation.proof_size', 2048);
        $row->refresh();
        $this->assertNotSame($oldPath, $row->proof_path);
        Storage::disk('proofs')->assertMissing($oldPath);
        Storage::disk('proofs')->assertExists($row->proof_path);
        $this->postJson($url, $this->proofFile())->assertConflict();
        $this->assertCount(1, Storage::disk('proofs')->allFiles());
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['type' => 'donation_needs_revision']);
        $this->getJson('/api/donation-summary')->assertJsonPath('total_donations', 0);
    }

    public function test_failed_revision_preserves_previous_proof_and_reason(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $url = '/api/donation-participations/'.$id.'/proof';
        $this->postJson($url, $this->proofFile())->assertOk();
        $row = DonationParticipation::findOrFail($id);
        $oldPath = $row->proof_path;
        $row->forceFill(['status' => 'needs_revision', 'revision_reason' => 'A clearer copy is needed.'])->save();
        $before = $row->getAttributes();
        $this->postJson($url, ['proof' => UploadedFile::fake()->create('wrong.txt', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson($url, [...$this->proofFile(), 'revision_reason' => 'Override'])->assertUnprocessable();
        DonationParticipation::saving(function (DonationParticipation $item) {
            if ($item->status === 'for_verification') {
                throw new \RuntimeException('Simulated replacement save failure');
            }
        });
        $this->postJson($url, $this->proofFile())->assertStatus(500);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame([$oldPath], Storage::disk('proofs')->allFiles());
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_revision_upload_is_owned_and_cleanup_cannot_delete_foreign_paths(): void
    {
        $owner = $this->signIn();
        $id = $this->postJson('/api/donation-opportunities/red-cross-dagupan/join')->assertCreated()->json('participation.id');
        $row = DonationParticipation::findOrFail($id);
        $row->forceFill(['status' => 'needs_revision', 'revision_reason' => 'Please replace proof.', 'proof_path' => 'unrelated.pdf'])->save();
        Storage::disk('proofs')->put('unrelated.pdf', 'preserve');
        $this->app['auth']->forgetGuards();
        $this->signIn();
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('revision-test')->plainTextToken);
        $this->postJson('/api/donation-participations/'.$id.'/proof', $this->proofFile())->assertOk();
        Storage::disk('proofs')->assertExists('unrelated.pdf');
    }

    public static function joinValidationCases(): array
    {
        $cases = [];
        foreach (['admin', 'red_cross'] as $source) {
            foreach (['pending', 'for_verification', 'none', 'expired_eligible', 'expired_not_eligible', 'exact_deadline', 'not_eligible', 'legacy', 'eligible'] as $state) {
                $cases[$source.' '.$state] = [$source, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('joinValidationCases')]
    public function test_ordered_join_validation_for_both_sources(string $source, string $state): void
    {
        $this->freezeTime();
        $user = $this->signIn(false);
        $admin = $this->opportunity();
        $route = '/api/donation-opportunities/'.($source === 'admin' ? $admin->id : 'red-cross-dagupan').'/join';
        $active = null;
        if (in_array($state, ['pending', 'for_verification'], true)) {
            // Use the OTHER source and no assessment: active activity must win.
            $active = new DonationParticipation;
            $active->forceFill(['user_id' => $user->id,
                'source_type' => $source === 'admin' ? 'red_cross_dagupan' : 'admin_announcement',
                'donation_opportunity_id' => $source === 'admin' ? null : $admin->id,
                'status' => $state, 'joined_at' => now()])->save();
        } elseif ($state !== 'none') {
            $result = in_array($state, ['not_eligible', 'expired_not_eligible'], true) ? 'not_eligible' : ($state === 'legacy' ? 'needs_further_screening' : 'eligible');
            $assessed = str_starts_with($state, 'expired_') ? now()->subHours(25) : ($state === 'exact_deadline' ? now()->subHours(24) : now()->subHours(2));
            $user->eligibilityAssessments()->create(['result' => $result, 'answers' => [], 'reasons' => [], 'assessed_at' => $assessed]);
        }
        // Another donor's eligible result and activity cannot affect this donor.
        $other = User::factory()->create();
        $other->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        $foreign = new DonationParticipation;
        $foreign->forceFill(['user_id' => $other->id, 'source_type' => 'red_cross_dagupan', 'status' => 'pending', 'joined_at' => now()])->save();
        $before = $user->eligibilityAssessments()->get()->toArray();
        $response = $this->postJson($route);
        if ($state === 'eligible') {
            $id = $response->assertCreated()->assertJsonPath('participation.status', 'pending')->json('participation.id');
            $this->postJson($route)->assertConflict()->assertJsonPath('reason', 'active_participation_exists')->assertJsonPath('participation_id', $id);
            $this->getJson('/api/donation-participations/'.$id)->assertOk();
            $this->assertSame(1, $user->donationParticipations()->count());
        } else {
            $reason = $active ? 'active_participation_exists' : (in_array($state, ['not_eligible', 'legacy'], true) ? 'evaluation_not_eligible' : 'evaluation_required');
            $response->assertConflict()->assertJsonPath('reason', $reason);
            if ($active) {
                $response->assertJsonPath('participation_id', $active->id);
            } elseif ($reason === 'evaluation_not_eligible') {
                $response->assertJsonPath('remaining_seconds', 22 * 3600)->assertJsonPath('cooldown_active', true);
            }
            $this->assertSame($active ? 1 : 0, $user->donationParticipations()->count());
        }
        $this->assertSame($before, $user->eligibilityAssessments()->get()->toArray());
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_latest_assessment_wins_and_last_second_is_current(): void
    {
        $this->freezeTime();
        $user = $this->signIn(false);
        $user->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()->subHours(2)]);
        $latest = $user->eligibilityAssessments()->create(['result' => 'not_eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()->subHour()]);
        $route = '/api/donation-opportunities/red-cross-dagupan/join';
        $this->postJson($route)->assertConflict()->assertJsonPath('reason', 'evaluation_not_eligible');
        $latest->update(['result' => 'eligible', 'assessed_at' => now()->subHours(24)->addSecond()]);
        // Move the earlier assessment farther back so the boundary row is latest.
        $user->eligibilityAssessments()->where('id', '!=', $latest->id)->update(['assessed_at' => now()->subDays(2)]);
        $this->postJson($route)->assertCreated();
    }

    private function signIn(bool $eligible = true): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        if ($eligible) {
            $user->eligibilityAssessments()->create(['result' => 'eligible', 'answers' => [], 'reasons' => [], 'assessed_at' => now()]);
        }

        return $user;
    }

    private function opportunity(array $overrides = []): DonationOpportunity
    {
        return DonationOpportunity::create(array_replace(['title' => 'Blood Drive', 'description' => 'Event information', 'location' => 'Community Center', 'event_date' => now()->toDateString(), 'points_reward' => 300, 'status' => 'published', 'published_at' => now()->subHour(), 'expires_at' => now()->addDay()], $overrides));
    }
}
