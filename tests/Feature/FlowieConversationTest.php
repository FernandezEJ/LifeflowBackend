<?php

namespace Tests\Feature;

use App\Models\FlowieConversation;
use App\Models\FlowieMessage;
use App\Models\User;
use App\Services\FlowieConversationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlowieConversationTest extends TestCase
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-03T00:00:00+08:00'));
        Http::preventStrayRequests();
        config(['services.groq.key' => 'test-secret-key', 'services.groq.model' => 'test-model']);
        $this->fakeReply();
    }

    private function fakeReply(?callable $callback = null): void
    {
        Http::fake([self::ENDPOINT => $callback ?? Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Rest and hydrate.', 'reasoning' => 'hidden reasoning']]],
        ])]);
    }

    private function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $token = $user->createToken('flowie-test');
        $this->withToken($token->plainTextToken);
        $this->app['auth']->forgetGuards();

        return $user->withAccessToken($token->accessToken);
    }

    private function send(string $message = 'How can I prepare?', array $extra = []): TestResponse
    {
        return $this->postJson('/api/flowie/chat', ['message' => $message, ...$extra]);
    }

    private function conversation(User $user, array $attributes = [], bool $ended = false): FlowieConversation
    {
        $factory = FlowieConversation::factory()->for($user);

        return ($ended ? $factory->ended() : $factory)->create($attributes);
    }

    private function path(int $id, string $suffix = ''): string
    {
        return '/api/flowie/conversations/'.$id.$suffix;
    }

    public function test_success_persists_only_actual_sides_and_reuses_active_conversation(): void
    {
        $user = $this->signIn();
        $first = $this->send('Donation preparation?', ['history' => [['role' => 'assistant', 'text' => 'Untrusted local FAQ']]])
            ->assertOk()->assertJsonPath('reply', 'Rest and hydrate.');
        $id = $first->json('conversation_id');
        $first->assertJsonMissingPath('user_id')->assertJsonMissingPath('token');
        $item = FlowieConversation::findOrFail($id);
        $this->assertSame($user->id, $item->user_id);
        $this->assertSame('Donation preparation?', $item->title);
        $this->assertSame(FlowieConversation::ACTIVE, $item->status);
        $this->assertNull($item->ended_at);
        $this->assertTrue($item->last_message_at->equalTo(now()));
        $this->assertSame(['user', 'assistant'], $item->messages()->orderBy('id')->pluck('role')->all());
        $this->assertSame(['Donation preparation?', 'Rest and hydrate.'], $item->messages()->orderBy('id')->pluck('content')->all());
        $this->travel(1)->seconds();
        $this->send('Aftercare?', ['conversation_id' => $id])->assertOk()->assertJsonPath('conversation_id', $id);
        $this->assertDatabaseCount('flowie_conversations', 1);
        $this->assertDatabaseCount('flowie_messages', 4);
        $this->assertSame('Donation preparation?', $item->fresh()->title);
        $this->assertTrue($item->fresh()->last_message_at->equalTo(now()));
        foreach (['point_transactions', 'donation_participations', 'eligibility_assessments', 'user_vouchers', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertSentCount(2);
    }

    public function test_different_donors_get_distinct_private_conversations(): void
    {
        $first = $this->signIn();
        $firstId = $this->send()->assertOk()->json('conversation_id');
        $second = $this->signIn();
        $this->getJson('/api/flowie/conversations')->assertOk()->assertJsonCount(0, 'data');
        $secondId = $this->send()->assertOk()->json('conversation_id');
        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseHas('flowie_conversations', ['id' => $secondId, 'user_id' => $second->id]);
        $this->assertDatabaseHas('flowie_conversations', ['id' => $firstId, 'user_id' => $first->id]);
        $this->getJson('/api/flowie/conversations?user_id='.$first->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $secondId);
    }

    public function test_titles_use_first_message_deterministically_without_extra_provider_call(): void
    {
        $this->signIn();
        $text = "Preparation\n".str_repeat('é', 100);
        $id = $this->send($text)->assertOk()->json('conversation_id');
        $this->assertSame(Str::limit(Str::squish($text), 80), FlowieConversation::findOrFail($id)->title);
        $this->assertLessThanOrEqual(100, mb_strlen(FlowieConversation::findOrFail($id)->title));
        Http::assertSentCount(1);
    }

    public function test_database_constraint_prevents_duplicate_active_conversations(): void
    {
        $user = $this->signIn();
        $first = $this->conversation($user);
        try {
            $this->conversation($user);
            $this->fail('The database must reject a second active conversation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('flowie_conversations', 1);
        }
        $first->delete();
        $this->conversation($user);
        $this->conversation($user, [], true);
        $this->assertSame(1, $user->flowieConversations()->where('status', 'active')->count());
    }

    public function test_history_is_paginated_and_sorted_by_last_message_then_id(): void
    {
        $user = $this->signIn();
        $older = $this->conversation($user, ['last_message_at' => now()->subDay()], true);
        FlowieConversation::factory()->for($user)->ended()->count(19)->create(['last_message_at' => now()->subHour()]);
        $newer = $this->conversation($user, ['last_message_at' => now()]);
        $deleted = $this->conversation($user, [], true);
        $deleted->delete();
        $this->conversation(User::factory()->create());
        $page = $this->getJson('/api/flowie/conversations')->assertOk()->assertJsonPath('total', 21)->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $newer->id);
        $page->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.active_slot');
        $this->getJson('/api/flowie/conversations?page=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
        $this->getJson('/api/flowie/conversations?page=0')->assertUnprocessable();
    }

    public function test_detail_returns_ordered_paginated_messages_and_fallback_title(): void
    {
        $user = $this->signIn();
        $item = $this->conversation($user, ['title' => null], true);
        $later = FlowieMessage::factory()->for($item, 'conversation')->assistant()->create(['created_at' => now()]);
        $earlier = FlowieMessage::factory()->for($item, 'conversation')->create(['created_at' => now()->subSecond()]);
        FlowieMessage::factory()->for($item, 'conversation')->count(49)->create(['created_at' => now()->addSecond()]);
        $this->getJson($this->path($item->id))->assertOk()->assertJsonPath('conversation.title', 'Flowie Conversation')
            ->assertJsonPath('conversation.status', 'ended')->assertJsonPath('messages.total', 51)
            ->assertJsonPath('messages.data.0.id', $earlier->id)->assertJsonPath('messages.data.1.id', $later->id)->assertJsonCount(50, 'messages.data');
        $this->getJson($this->path($item->id).'?page=2')->assertJsonCount(1, 'messages.data');
    }

    public static function foreignActions(): array
    {
        return [['detail'], ['end'], ['delete'], ['restore'], ['chat']];
    }

    #[DataProvider('foreignActions')]
    public function test_foreign_ids_are_not_found_and_never_mutate_history(string $action): void
    {
        $foreign = $this->conversation(User::factory()->create());
        FlowieMessage::factory()->for($foreign, 'conversation')->create();
        if ($action === 'restore') {
            $foreign->delete();
        }
        $this->signIn();
        $response = match ($action) {
            'detail' => $this->getJson($this->path($foreign->id)),
            'end' => $this->postJson($this->path($foreign->id, '/end')),
            'delete' => $this->deleteJson($this->path($foreign->id)),
            'restore' => $this->postJson($this->path($foreign->id, '/restore')),
            'chat' => $this->send('Hi', ['conversation_id' => $foreign->id]),
        };
        $response->assertNotFound();
        $this->assertDatabaseCount('flowie_messages', 1);
        $this->assertSame('active', $foreign->fresh()->status);
        $this->assertSame($action === 'restore', $foreign->fresh()->trashed());
        Http::assertNothingSent();
    }

    private function allEndpoints(int $id): array
    {
        return [['POST', '/api/flowie/chat', ['message' => 'Hi']],
            ['GET', '/api/flowie/conversations', []],
            ['GET', '/api/flowie/conversations/recently-deleted', []],
            ['GET', $this->path($id), []],
            ['POST', $this->path($id, '/end'), []],
            ['POST', '/api/flowie/conversations/active/end', []],
            ['DELETE', $this->path($id), []],
            ['POST', $this->path($id, '/restore'), []]];
    }

    public function test_every_flowie_endpoint_requires_sanctum(): void
    {
        $item = $this->conversation(User::factory()->create());
        foreach ($this->allEndpoints($item->id) as [$method, $path, $data]) {
            $this->json($method, $path, $data)->assertUnauthorized();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('flowie_messages', 0);
    }

    public static function forbiddenUsers(): array
    {
        return [['admin'], ['super_admin'], ['deactivated'], ['deleted']];
    }

    #[DataProvider('forbiddenUsers')]
    public function test_staff_and_inactive_users_cannot_use_any_donor_flowie_route(string $kind): void
    {
        $user = User::factory()->create();
        $item = $this->conversation($user);
        $this->signIn($user);
        $user->forceFill(match ($kind) {
            'deactivated' => ['deactivated_at' => now()],
            'deleted' => ['deleted_at' => now()],
            default => ['role' => $kind],
        })->save();
        foreach ($this->allEndpoints($item->id) as [$method, $path, $data]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $path, $data)->assertStatus($kind === 'deleted' ? 401 : 403);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('flowie_messages', 0);
    }

    public function test_end_is_idempotent_read_only_and_next_send_creates_new_chat(): void
    {
        $this->signIn();
        $id = $this->send()->assertOk()->json('conversation_id');
        $ended = $this->postJson($this->path($id, '/end'))->assertOk()->assertJsonPath('conversation.status', 'ended')->json('conversation.ended_at');
        $this->travel(1)->hours();
        $this->postJson($this->path($id, '/end'))->assertOk()->assertJsonPath('conversation.ended_at', $ended);
        $this->getJson('/api/flowie/conversations')->assertJsonCount(1, 'data');
        $this->getJson($this->path($id))->assertOk()->assertJsonCount(2, 'messages.data');
        $this->send('Cannot append', ['conversation_id' => $id])->assertConflict();
        $this->assertDatabaseCount('flowie_messages', 2);
        $nextId = $this->send('New chat')->assertOk()->json('conversation_id');
        $this->assertNotSame($id, $nextId);
        $this->postJson('/api/flowie/conversations/active/end')->assertOk()->assertJsonPath('conversation.id', $nextId);
        $this->postJson('/api/flowie/conversations/active/end')->assertOk()->assertJsonPath('conversation', null);
        Http::assertSentCount(2);
    }

    public function test_end_with_no_active_conversation_is_safe(): void
    {
        $this->signIn();
        $this->postJson('/api/flowie/conversations/active/end')->assertOk()->assertExactJson(['message' => 'No active conversation.', 'conversation' => null]);
        $this->postJson('/api/flowie/conversations/active/end')->assertOk();
        $this->assertDatabaseCount('flowie_conversations', 0);
    }

    public function test_soft_delete_hides_content_preserves_messages_and_does_not_extend_deadline(): void
    {
        $this->signIn();
        $id = $this->send()->assertOk()->json('conversation_id');
        $deleted = $this->deleteJson($this->path($id))->assertOk()->json('conversation.deleted_at');
        $this->getJson('/api/flowie/conversations')->assertJsonCount(0, 'data');
        $this->getJson($this->path($id))->assertNotFound();
        $this->send('Deleted', ['conversation_id' => $id])->assertNotFound();
        $this->postJson($this->path($id, '/end'))->assertNotFound();
        $this->assertDatabaseCount('flowie_messages', 2);
        $this->travel(1)->days();
        $this->deleteJson($this->path($id))->assertOk()->assertJsonPath('conversation.deleted_at', $deleted);
        $next = $this->send('New')->assertOk()->json('conversation_id');
        $this->assertNotSame($id, $next);
        $this->assertSame(1, FlowieConversation::where('status', 'active')->count());
    }

    public function test_recently_deleted_is_private_sorted_and_has_reliable_recovery_metadata(): void
    {
        $user = $this->signIn();
        $older = $this->conversation($user, ['deleted_at' => now()->subDays(2)], true);
        $newer = $this->conversation($user, ['deleted_at' => now()->subDay()], true);
        $expired = $this->conversation($user, ['deleted_at' => now()->subDays(30)], true);
        $this->conversation(User::factory()->create(), ['deleted_at' => now()]);
        $this->conversation($user);
        $result = $this->getJson('/api/flowie/conversations/recently-deleted')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.days_remaining', 29)->assertJsonPath('data.0.recoverable', true)
            ->assertJsonPath('data.2.id', $expired->id)->assertJsonPath('data.2.days_remaining', 0)->assertJsonPath('data.2.recoverable', false);
        $this->assertSame($newer->deleted_at->copy()->addDays(30)->toISOString(), $result->json('data.0.permanent_delete_at'));
        $result->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.messages');
    }

    public static function restoreCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('restoreCases')]
    public function test_restoring_formerly_active_conversation_always_returns_ended_history(bool $hasActive): void
    {
        $user = $this->signIn();
        $old = $this->conversation($user);
        FlowieMessage::factory()->for($old, 'conversation')->create();
        $old->delete();
        $active = $hasActive ? $this->conversation($user) : null;
        $this->postJson($this->path($old->id, '/restore'))->assertOk()->assertJsonPath('conversation.status', 'ended');
        $this->assertFalse($old->fresh()->trashed());
        $this->assertNotNull($old->fresh()->ended_at);
        $this->assertSame($hasActive ? 1 : 0, $user->flowieConversations()->where('status', 'active')->count());
        if ($active) {
            $this->assertSame('active', $active->fresh()->status);
        }
        $this->getJson($this->path($old->id))->assertOk()->assertJsonCount(1, 'messages.data');
        $this->send('Read-only', ['conversation_id' => $old->id])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_restoring_ended_conversation_preserves_original_end_timestamp(): void
    {
        $user = $this->signIn();
        $old = $this->conversation($user, ['ended_at' => now()->subDays(10)], true);
        $old->delete();
        $this->postJson($this->path($old->id, '/restore'))->assertOk()->assertJsonPath('conversation.ended_at', $old->ended_at->toISOString());
        $this->getJson('/api/flowie/conversations')->assertJsonCount(1, 'data');
        $this->postJson($this->path($old->id, '/restore'))->assertNotFound();
    }

    public static function recoveryBoundaries(): array
    {
        return [[-1, 200], [0, 410], [1, 410]];
    }

    #[DataProvider('recoveryBoundaries')]
    public function test_restore_enforces_exact_thirty_day_boundary(int $seconds, int $status): void
    {
        $user = $this->signIn();
        $item = $this->conversation($user, ['deleted_at' => now()->subDays(30)->subSeconds($seconds)], true);
        $this->postJson($this->path($item->id, '/restore'))->assertStatus($status);
        $this->assertSame($status !== 200, $item->fresh()->trashed());
    }

    public function test_daily_purge_deletes_only_expired_soft_deletions_and_cascades_messages(): void
    {
        $user = User::factory()->create();
        $recent = $this->conversation($user, ['deleted_at' => now()->subDays(30)->addSecond()], true);
        $exact = $this->conversation($user, ['deleted_at' => now()->subDays(30)], true);
        $older = $this->conversation($user, ['deleted_at' => now()->subDays(31)], true);
        $ended = $this->conversation($user, ['ended_at' => now()->subYear()], true);
        $active = $this->conversation($user);
        foreach ([$recent, $exact, $older, $ended, $active] as $item) {
            FlowieMessage::factory()->for($item, 'conversation')->create();
        }
        $this->artisan('flowie:purge-deleted')->expectsOutput('Purged 2 Flowie conversation(s).')->assertExitCode(0);
        $this->assertDatabaseMissing('flowie_conversations', ['id' => $exact->id]);
        $this->assertDatabaseMissing('flowie_conversations', ['id' => $older->id]);
        foreach ([$recent, $ended, $active] as $item) {
            $this->assertDatabaseHas('flowie_conversations', ['id' => $item->id]);
            $this->assertDatabaseHas('flowie_messages', ['flowie_conversation_id' => $item->id]);
        }
        $this->assertDatabaseCount('flowie_messages', 3);
        $this->artisan('flowie:purge-deleted')->expectsOutput('Purged 0 Flowie conversation(s).')->assertExitCode(0);
    }

    public function test_provider_failure_preserves_user_message_without_fake_reply_and_retry_reuses_chat(): void
    {
        $this->signIn();
        config(['services.groq.key' => '']);
        $this->send()->assertStatus(503)->assertExactJson(['message' => 'Flowie is unavailable right now. Please try again shortly.']);
        $item = FlowieConversation::sole();
        $this->assertSame('active', $item->status);
        $this->assertSame(['user'], $item->messages()->pluck('role')->all());
        $this->assertSame('How can I prepare?', $item->messages()->sole()->content);
        $this->assertTrue($item->last_message_at->equalTo(now()));
        Http::assertNothingSent();
        config(['services.groq.key' => 'test-secret-key']);
        $this->send()->assertOk()->assertJsonPath('conversation_id', $item->id);
        $this->assertSame(['user', 'user', 'assistant'], $item->messages()->orderBy('id')->pluck('role')->all());
        $this->assertDatabaseCount('flowie_conversations', 1);
    }

    public function test_busy_send_is_rejected_before_persistence_and_provider_call(): void
    {
        $user = $this->signIn();
        $lock = Cache::lock('flowie-chat:'.$user->id, 90);
        $this->assertTrue($lock->get());
        try {
            $this->send()->assertConflict();
            $this->assertDatabaseCount('flowie_conversations', 0);
            $this->assertDatabaseCount('flowie_messages', 0);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->send()->assertOk();
    }

    public static function inFlightActions(): array
    {
        return [['end', 409], ['delete', 404], ['restore', 409], ['revoke', 401], ['deactivate', 403]];
    }

    #[DataProvider('inFlightActions')]
    public function test_provider_call_cannot_append_after_lifecycle_or_session_changes(string $action, int $status): void
    {
        $user = $this->signIn();
        $this->fakeReply(function () use ($user, $action) {
            $item = FlowieConversation::sole();
            $service = app(FlowieConversationService::class);
            match ($action) {
                'end' => $service->end($user, $item->id),
                'delete' => $service->delete($user, $item->id),
                'restore' => (function () use ($service, $user, $item) {
                    $service->delete($user, $item->id);
                    $service->restore($user, $item->id);
                })(),
                'revoke' => $user->currentAccessToken()->delete(),
                'deactivate' => $user->forceFill(['deactivated_at' => now()])->save(),
            };

            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Late reply']]]]);
        });
        $this->send()->assertStatus($status);
        $this->assertDatabaseCount('flowie_messages', 1);
        $this->assertSame('user', FlowieMessage::sole()->role);
        $lock = Cache::lock('flowie-chat:'.$user->id, 90);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public static function invalidConversationIds(): array
    {
        return [[0], ['bad'], [null], [[]]];
    }

    #[DataProvider('invalidConversationIds')]
    public function test_invalid_conversation_selection_never_creates_history(mixed $id): void
    {
        $this->signIn();
        $this->send('Hi', ['conversation_id' => $id])->assertUnprocessable();
        $this->assertDatabaseCount('flowie_conversations', 0);
        Http::assertNothingSent();
    }

    public function test_clients_cannot_choose_owner_status_or_recovery_deadlines(): void
    {
        $user = $this->signIn();
        $item = $this->conversation($user);
        foreach (['end', 'delete', 'restore'] as $action) {
            $path = $this->path($item->id, $action === 'delete' ? '' : '/'.$action);
            $this->json($action === 'delete' ? 'DELETE' : 'POST', $path, ['user_id' => 99, 'status' => 'active', 'deleted_at' => now()->toISOString()])->assertUnprocessable();
        }
        $this->send('Hi', ['user_id' => 99])->assertUnprocessable();
        $this->assertFalse($item->fresh()->trashed());
        $this->assertSame('active', $item->fresh()->status);
        Http::assertNothingSent();
    }
}
