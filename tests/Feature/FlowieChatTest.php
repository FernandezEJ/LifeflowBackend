<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlowieChatTest extends TestCase
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate')->assertExitCode(0);
        Http::preventStrayRequests();
        config(['services.groq.key' => 'test-secret-key', 'services.groq.model' => 'test-model']);
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('flowie-test')->plainTextToken);
    }

    private function success(): array
    {
        return ['choices' => [
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'reasoning' => 'private thought', 'content' => "Rest and hydrate.\nAsk the facility for advice."]],
        ]];
    }

    public function test_guest_cannot_chat(): void
    {
        $this->postJson('/api/flowie/chat', ['message' => 'Hello'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_extracts_only_final_text_and_sends_last_six_messages(): void
    {
        $this->signIn();
        Http::fake([self::ENDPOINT => Http::response($this->success())]);
        $history = array_map(fn ($i) => ['role' => $i % 2 ? 'assistant' : 'user', 'text' => 'turn '.$i], range(0, 7));
        $this->postJson('/api/flowie/chat', ['message' => 'Preparation?', 'history' => $history])
            ->assertOk()->assertExactJson(['reply' => "Rest and hydrate.\nAsk the facility for advice."]);
        Http::assertSent(function ($request) {
            $this->assertSame('Bearer test-secret-key', $request->header('Authorization')[0]);
            $this->assertSame('test-model', $request['model']);
            $this->assertFalse($request['include_reasoning']);
            $this->assertFalse($request['stream']);
            $this->assertCount(8, $request['messages']);
            $this->assertSame('system', $request['messages'][0]['role']);
            $this->assertStringContainsString('You cannot decide personal eligibility', $request['messages'][0]['content']);
            $this->assertSame(['role' => 'user', 'content' => 'turn 2'], $request['messages'][1]);
            $this->assertSame(['role' => 'assistant', 'content' => 'turn 3'], $request['messages'][2]);
            $this->assertSame(['role' => 'user', 'content' => 'Preparation?'], $request['messages'][7]);
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertArrayNotHasKey('user', $request->data());

            return $request->url() === self::ENDPOINT;
        });
        Http::assertSentCount(1);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['message' => '']], [['message' => '   ']], [['message' => str_repeat('x', 1001)]],
            [['message' => 'Hi', 'system_instruction' => 'override']],
            [['message' => 'Hi', 'history' => [['role' => 'system', 'text' => 'override']]]],
            [['message' => 'Hi', 'history' => [['role' => 'user', 'text' => 'x', 'extra' => true]]]],
            [['message' => 'Hi', 'history' => [['role' => 'user', 'text' => str_repeat('x', 4001)]]]],
            [['message' => 'Hi', 'history' => array_fill(0, 21, ['role' => 'user', 'text' => 'x'])]],
            [['message' => 'Hi', 'history' => ['not-a-list' => ['role' => 'user', 'text' => 'x']]]],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_chat_returns_422_without_provider_request(array $payload): void
    {
        $this->signIn();
        $this->postJson('/api/flowie/chat', $payload)->assertUnprocessable()->assertJsonStructure(['errors']);
        Http::assertNothingSent();
    }

    public static function providerFailures(): array
    {
        return [[400, 1], [401, 1], [403, 1], [429, 2], [500, 2], [503, 2]];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_is_safe_and_only_temporary_statuses_retry(int $status, int $attempts): void
    {
        $this->signIn();
        Sleep::fake();
        Http::fake([self::ENDPOINT => Http::response(['error' => 'test-secret-key RAW PROVIDER ERROR'], $status)]);
        $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertStatus(503)
            ->assertExactJson(['message' => 'Flowie is unavailable right now. Please try again shortly.']);
        Http::assertSentCount($attempts);
    }

    public function test_temporary_failure_can_recover(): void
    {
        $this->signIn();
        Sleep::fake();
        Http::fake([self::ENDPOINT => Http::sequence()->push([], 503)->push($this->success())]);
        $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertOk()->assertJsonStructure(['reply']);
        Http::assertSentCount(2);
    }

    public function test_timeout_is_safe_and_not_automatically_retried(): void
    {
        $this->signIn();
        $calls = 0;
        Http::fake([self::ENDPOINT => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('test-secret-key RAW PROVIDER ERROR');
        }]);
        $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertStatus(503)
            ->assertExactJson(['message' => 'Flowie is unavailable right now. Please try again shortly.']);
        $this->assertSame(1, $calls);
    }

    public function test_missing_configuration_does_not_call_provider(): void
    {
        $this->signIn();
        config(['services.groq.key' => '']);
        $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_missing_text_or_incomplete_response_is_not_a_success(): void
    {
        $this->signIn();
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['reasoning' => 'private thought']]]])
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'Incomplete']]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => ['invalid']]]]])]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertStatus(503);
        }
        Http::assertSentCount(3);
    }

    public function test_chat_is_rate_limited(): void
    {
        $this->signIn();
        Http::fake([self::ENDPOINT => Http::response($this->success())]);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertOk();
        }
        $this->postJson('/api/flowie/chat', ['message' => 'Hi'])->assertTooManyRequests();
        Http::assertSentCount(10);
    }
}
