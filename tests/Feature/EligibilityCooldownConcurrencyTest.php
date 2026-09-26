<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\EligibilityEvaluatorTest;

class EligibilityCooldownConcurrencyTest extends TestCase
{
    private string $databaseFile;

    private array $workers = [];

    // Only disposable test storage is shared by these two real PHP processes.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'lifeflow-eligibility-test-');
        config(['database.connections.sqlite.database' => $this->databaseFile, 'database.connections.sqlite.busy_timeout' => 5000]);
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

    public function test_simultaneous_http_submissions_create_one_assessment(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('race')->plainTextToken;
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
            'database.connections.sqlite.busy_timeout' => 5000, 'services.fcm.enabled' => false]);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        echo "ready\n"; fflush(STDOUT);
        fgets(STDIN);
        $request = \Illuminate\Http\Request::create('/api/eligibility-assessments', 'POST', [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$argv[2], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $argv[3]);
        $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
        PHP;
        $streams = [];
        for ($i = 0; $i < 2; $i++) {
            $stream = new InputStream;
            $worker = new Process([PHP_BINARY, '-r', $code, $this->databaseFile, $token,
                json_encode(['answers' => EligibilityEvaluatorTest::answers()])], base_path());
            $worker->setInput($stream);
            $worker->setTimeout(20);
            $worker->start();
            $this->workers[] = $worker;
            $streams[] = $stream;
        }
        $deadline = microtime(true) + 10;
        foreach ($this->workers as $worker) {
            while (! str_contains($worker->getOutput(), 'ready') && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $worker->getOutput(), $worker->getErrorOutput());
        }
        foreach ($streams as $stream) {
            $stream->write("go\n");
            $stream->close();
        }
        $responses = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $responses[] = json_decode(trim(str_replace('ready', '', $worker->getOutput())), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($responses));
        $this->assertSame($responses[0]['body']['assessment']['id'], $responses[1]['body']['assessment']['id']);
        $this->assertDatabaseCount('eligibility_assessments', 1);
    }
}
