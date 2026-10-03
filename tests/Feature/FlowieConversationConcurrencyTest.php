<?php

namespace Tests\Feature;

use App\Models\FlowieConversation;
use App\Models\FlowieMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FlowieConversationConcurrencyTest extends TestCase
{
    private string $databaseFile;

    private array $workers = [];

    /** Two real HTTP workers share only disposable SQLite storage, never the donor database. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'lifeflow-flowie-test-');
        config(['database.connections.sqlite.database' => $this->databaseFile,
            'database.connections.sqlite.busy_timeout' => 5000]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::disconnect('sqlite');
        if (isset($this->databaseFile) && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
        parent::tearDown();
    }

    private function race(string $token, ?int $restoreId = null): array
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
            'database.connections.sqlite.busy_timeout' => 5000,
            'cache.default' => 'database', 'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite', 'cache.stores.database.lock_table' => 'cache_locks',
            'cache.prefix' => 'flowie-test:', 'services.groq.key' => 'test-only', 'services.groq.model' => 'test-model']);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://api.groq.com/openai/v1/chat/completions' => function () {
            echo "provider-ready\n"; fflush(STDOUT);
            fgets(STDIN);
            return \Illuminate\Support\Facades\Http::response(['choices' => [
                ['finish_reason' => 'stop', 'message' => ['content' => 'Test reply']],
            ]]);
        }]);
        echo "ready\n"; fflush(STDOUT);
        fgets(STDIN);
        $path = $argv[3] === 'chat' ? '/api/flowie/chat' : '/api/flowie/conversations/'.$argv[3].'/restore';
        $request = \Illuminate\Http\Request::create($path, 'POST', [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$argv[2], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $argv[3] === 'chat' ? json_encode(['message' => 'Hello']) : '{}');
        $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
        echo 'result:'.json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
        PHP;
        $streams = [];
        foreach ([$restoreId === null ? 'chat' : (string) $restoreId, 'chat'] as $action) {
            $stream = new InputStream;
            $worker = new Process([PHP_BINARY, '-r', $code, $this->databaseFile, $token, $action], base_path());
            $worker->setInput($stream);
            $worker->setTimeout(30);
            $worker->start();
            $this->workers[] = $worker;
            $streams[] = $stream;
        }
        $deadline = microtime(true) + 15;
        foreach ($this->workers as $worker) {
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString("ready\n", $worker->getOutput(), $worker->getErrorOutput());
        }
        foreach ($streams as $stream) {
            $stream->write("go\n");
        }
        // The winning provider waits until the other HTTP request has finished, guaranteeing overlap.
        while (microtime(true) < $deadline) {
            $providers = array_filter($this->workers, fn ($worker) => str_contains($worker->getOutput(), 'provider-ready'));
            $finished = array_filter($this->workers, fn ($worker) => str_contains($worker->getOutput(), 'result:'));
            if ($providers && $finished) {
                break;
            }
            usleep(10000);
        }
        $this->assertNotEmpty($providers ?? []);
        $this->assertNotEmpty($finished ?? [], implode('\n', array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $this->workers)));
        foreach ($streams as $stream) {
            $stream->write("reply\n");
            $stream->close();
        }
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $output = $worker->getOutput();
            $results[] = json_decode(substr($output, strpos($output, 'result:') + 7), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_simultaneous_sends_create_one_active_conversation_and_one_real_reply(): void
    {
        $user = User::factory()->create();
        $responses = $this->race($user->createToken('race')->plainTextToken);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($responses));
        $this->assertDatabaseCount('flowie_conversations', 1);
        $this->assertDatabaseCount('flowie_messages', 2);
        $this->assertSame(['user', 'assistant'], FlowieMessage::orderBy('id')->pluck('role')->all());
        $this->assertSame(1, FlowieConversation::where('user_id', $user->id)->where('status', 'active')->count());
    }

    public function test_restore_racing_with_new_chat_keeps_restored_history_ended(): void
    {
        $user = User::factory()->create();
        $old = FlowieConversation::factory()->for($user)->create();
        FlowieMessage::factory()->for($old, 'conversation')->create();
        $old->delete();
        $responses = $this->race($user->createToken('race')->plainTextToken, $old->id);
        $this->assertSame([200, 200], array_column($responses, 'status'), json_encode($responses));
        $this->assertSame('ended', $old->fresh()->status);
        $this->assertFalse($old->fresh()->trashed());
        $this->assertSame(1, FlowieConversation::where('user_id', $user->id)->where('status', 'active')->count());
        $this->assertDatabaseCount('flowie_messages', 3);
    }
}
