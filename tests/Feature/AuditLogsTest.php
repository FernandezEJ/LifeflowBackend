<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AuditLogService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-04 01:30:00');
    }

    private function signIn(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'super_admin', ...$attributes]);
        $this->withToken($user->createToken('audit-test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    private function log(?User $actor = null, array $attributes = []): AuditLog
    {
        $log = new AuditLog(['actor_user_id' => $actor?->id, 'actor_role' => $actor?->role ?? 'admin',
            'action' => 'verification_approved', 'module' => 'verification',
            'target_type' => 'donation_participation', 'target_id' => 123, ...$attributes]);
        if (isset($attributes['created_at'])) {
            $log->created_at = $attributes['created_at'];
        }
        $log->save();

        return $log;
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertUnauthorized();
    }

    public static function blockedAccounts(): array
    {
        return ['donor' => [['role' => 'donor']], 'admin' => [['role' => 'admin']],
            'pending password' => [['must_change_password' => true]], 'deactivated' => [['deactivated_at' => '2026-10-01']]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_non_super_admins_and_unqualified_accounts_are_forbidden(array $attributes): void
    {
        $this->signIn($attributes);
        $this->getJson('/api/admin/audit-logs')->assertForbidden();
    }

    public function test_super_admin_receives_only_safe_real_display_fields(): void
    {
        $actor = $this->signIn(['name' => 'Real Super Admin']);
        $log = $this->log($actor, ['details' => ['reason' => 'A clearer image is needed',
            'password' => 'private-password', 'token' => 'private-token', 'otp' => 'private-otp',
            'proof_path' => 'private/proof.jpg', 'request' => ['email_code' => 'private-code']]]);

        $response = $this->getJson('/api/admin/audit-logs')->assertOk()->assertExactJson([
            'data' => [['id' => $log->id, 'actorName' => 'Real Super Admin', 'actorRole' => 'super_admin',
                'action' => 'verification_approved', 'module' => 'verification', 'target' => 'Donation Participation #123',
                'details' => 'A clearer image is needed', 'createdAt' => '2026-10-04T01:30:00.000000Z']],
            'pagination' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 1],
            'modules' => ['verification'],
        ]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['private-password', 'private-token', 'private-otp', 'private/proof.jpg', 'private-code', $actor->email] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/admin/audit-logs?search=private')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_deleted_and_missing_actors_use_historical_role_and_safe_fallbacks(): void
    {
        $actor = $this->signIn();
        $softDeleted = User::factory()->create(['name' => 'Historical Admin', 'role' => 'admin', 'deleted_at' => now()]);
        $soft = $this->log($softDeleted);
        $unknown = $this->log();
        $system = $this->log(null, ['action' => 'admin_purged', 'module' => 'admin_accounts', 'details' => ['initiator' => 'scheduler']]);
        $snapshot = $this->log($actor);
        $actor->forceFill(['role' => 'admin'])->save();
        $this->signIn();

        $rows = collect($this->getJson('/api/admin/audit-logs')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Historical Admin', $rows[$soft->id]['actorName']);
        $this->assertSame('Unknown User', $rows[$unknown->id]['actorName']);
        $this->assertSame('System', $rows[$system->id]['actorName']);
        $this->assertSame('Initiator: Scheduler', $rows[$system->id]['details']);
        $this->assertSame('super_admin', $rows[$snapshot->id]['actorRole']);
        $this->getJson('/api/admin/audit-logs?search=historical')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/audit-logs?search=unknown')->assertOk()->assertJsonPath('data.0.id', $unknown->id);
        $this->getJson('/api/admin/audit-logs?search=system')->assertOk()->assertJsonPath('data.0.id', $system->id);
        $this->getJson('/api/admin/audit-logs?search=scheduler')->assertOk()->assertJsonPath('data.0.id', $system->id);
    }

    public function test_pagination_is_bounded_and_module_options_cover_the_entire_history(): void
    {
        $actor = $this->signIn();
        $ids = [];
        for ($index = 0; $index < 21; $index++) {
            $ids[] = $this->log($actor, ['module' => $index === 0 ? 'admin_accounts' : 'rewards'])->id;
        }
        $first = $this->getJson('/api/admin/audit-logs')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('pagination', ['currentPage' => 1, 'lastPage' => 2, 'perPage' => 20, 'total' => 21])
            ->assertJsonPath('modules', ['admin_accounts', 'rewards']);
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), array_column($first->json('data'), 'id'));
        $this->getJson('/api/admin/audit-logs?page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('pagination.currentPage', 2);
        $this->getJson('/api/admin/audit-logs?module=admin_accounts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $ids[0]);
    }

    public function test_ordering_uses_created_at_then_id_descending(): void
    {
        $actor = $this->signIn();
        $newest = $this->log($actor, ['created_at' => '2026-10-04 00:00:00']);
        $older = $this->log($actor, ['created_at' => '2026-10-01 00:00:00']);
        $tied = $this->log($actor, ['created_at' => '2026-10-01 00:00:00']);
        $response = $this->getJson('/api/admin/audit-logs')->assertOk();
        $this->assertSame([$newest->id, $tied->id, $older->id], array_column($response->json('data'), 'id'));
    }

    public function test_search_and_module_filters_work_across_the_history_with_literal_search_characters(): void
    {
        $actor = $this->signIn(['name' => 'Alice Reviewer']);
        $log = $this->log($actor, ['action' => 'verification_needs_revision', 'details' => ['reason' => 'Please upload a clearer image']]);
        $other = User::factory()->create(['name' => '100% Admin', 'role' => 'admin']);
        $literal = $this->log($other, ['module' => 'rewards']);
        foreach ([' ALICE ', 'needs revision', 'verification_needs_revision', 'CLEARER IMAGE'] as $search) {
            $this->getJson('/api/admin/audit-logs?'.http_build_query(['search' => $search, 'module' => 'verification']))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $log->id);
        }
        $this->getJson('/api/admin/audit-logs?search=%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson('/api/admin/audit-logs?search=Alice&module=rewards')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/admin/audit-logs?'.http_build_query(['search' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_month_filter_uses_manila_calendar_boundaries_across_years(): void
    {
        $actor = $this->signIn();
        $dates = ['2026-08-31 15:59:59', '2026-08-31 16:00:00', '2026-09-30 15:59:59', '2026-09-30 16:00:00', '2025-09-01 00:00:00'];
        $ids = array_map(fn (string $date): int => $this->log($actor, ['created_at' => $date])->id, $dates);
        $response = $this->getJson('/api/admin/audit-logs?month=09')->assertOk()->assertJsonPath('pagination.total', 3);
        $this->assertSame([$ids[2], $ids[1], $ids[4]], array_column($response->json('data'), 'id'));
        $this->getJson('/api/admin/audit-logs?month=10')->assertOk()->assertJsonPath('data.0.id', $ids[3])->assertJsonCount(1, 'data');
    }

    public function test_malformed_metadata_unknown_roles_and_missing_timestamps_do_not_leak_or_crash(): void
    {
        $this->signIn();
        $log = $this->log(null, ['target_type' => null, 'target_id' => null,
            'details' => ['reason' => ['token' => 'nested-secret'], 'initiator' => ['password' => 'private']]]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => null, 'actor_role' => 'legacy_role']);
        $this->getJson('/api/admin/audit-logs')->assertOk()->assertJsonPath('data.0.actorRole', null)
            ->assertJsonPath('data.0.details', null)->assertJsonPath('data.0.target', null)->assertJsonPath('data.0.createdAt', null);
        $this->getJson('/api/admin/audit-logs?search=nested-secret')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/admin/audit-logs?search=unknown')->assertOk()->assertJsonPath('pagination.total', 1);
    }

    public static function invalidFilters(): array
    {
        return [
            'search array' => ['search', ['invalid']], 'long search' => ['search', str_repeat('x', 256)],
            'module array' => ['module', ['invalid']], 'long module' => ['module', str_repeat('x', 256)],
            'month zero' => ['month', 0], 'month above twelve' => ['month', 13], 'month text' => ['month', 'September'],
            'page zero' => ['page', 0], 'page fraction' => ['page', 1.5], 'page overflow' => ['page', 2147483648],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_query_inputs_receive_422(string $field, mixed $value): void
    {
        $this->signIn();
        $this->getJson('/api/admin/audit-logs?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_empty_and_filtered_empty_responses_are_truthful(): void
    {
        $actor = $this->signIn();
        $this->getJson('/api/admin/audit-logs')->assertOk()->assertExactJson(['data' => [], 'modules' => [],
            'pagination' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 0]]);
        $this->log($actor);
        $this->getJson('/api/admin/audit-logs?search=no-match')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total', 0)->assertJsonPath('modules', ['verification']);
    }

    public function test_get_is_read_only_and_no_mutation_or_purge_routes_are_available(): void
    {
        $actor = $this->signIn();
        $log = $this->log($actor, ['details' => ['reason' => 'Original reason']]);
        $before = DB::table('audit_logs')->get()->toArray();
        $this->getJson('/api/admin/audit-logs')->assertOk();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/admin/audit-logs', ['details' => 'Changed'])->assertStatus(405);
            $this->json($method, '/api/admin/audit-logs/'.$log->id)->assertNotFound();
        }
        $this->postJson('/api/admin/audit-logs/purge')->assertNotFound();
        $this->assertEquals($before, DB::table('audit_logs')->get()->toArray());
    }

    public function test_paginated_actor_loading_has_a_bounded_number_of_queries(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $this->log(User::factory()->create(['role' => 'admin']));
        }
        DB::enableQueryLog();
        $result = app(AuditLogService::class)->listing([]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(20, $result['data']);
        $this->assertSame(25, $result['pagination']['total']);
        $this->assertLessThanOrEqual(4, count($queries));
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
    }
}
