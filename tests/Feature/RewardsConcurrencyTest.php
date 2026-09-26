<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RewardsConcurrencyTest extends TestCase
{
    private string $databaseFile;

    private array $workers = [];

    // ========================================
    // DISPOSABLE MULTI-CONNECTION TEST DATABASE
    // Two real PHP processes share only this newly created SQLite file.
    // Existing MySQL donor data is never opened or changed by these tests.
    // ========================================
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'lifeflow-rewards-test-');
        config(['database.connections.sqlite.database' => $this->databaseFile, 'database.connections.sqlite.busy_timeout' => 5000]);
        DB::purge('sqlite');
        $this->artisan('migrate')->assertExitCode(0);
        Queue::fake();
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

    private function participation(User $user, string $status): DonationParticipation
    {
        $opportunity = DonationOpportunity::create(['title' => 'Concurrent test', 'description' => 'Isolated', 'location' => 'Test',
            'event_date' => now()->toDateString(), 'status' => 'published']);
        $row = new DonationParticipation;
        $row->forceFill(['user_id' => $user->id, 'donation_opportunity_id' => $opportunity->id, 'joined_at' => now(), 'status' => $status])->save();

        return $row;
    }

    // ========================================
    // REAL SIMULTANEOUS REQUESTS
    // Both workers load the application before a shared start signal is sent.
    // No database/query builder mocks are used.
    // ========================================
    private function race(array $attempts, string $mode = 'redeem'): array
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
            'database.connections.sqlite.busy_timeout' => 5000, 'services.fcm.enabled' => false]);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\Queue::fake();
        echo "ready\n"; fflush(STDOUT);
        fgets(STDIN);
        try {
            if ($argv[5] === 'complete') {
                $row = \App\Models\DonationParticipation::findOrFail((int) $argv[3]);
                $row->forceFill(['status' => 'completed'])->save();
            } else {
                app(\App\Services\RewardRedemptionService::class)->redeem((int) $argv[2], (int) $argv[3], $argv[4]);
            }
            echo 'ok';
        } catch (\Illuminate\Validation\ValidationException $error) {
            echo 'unavailable';
        }
        PHP;
        $streams = [];
        foreach ($attempts as [$userId, $id, $key]) {
            $stream = new InputStream;
            $process = new Process([PHP_BINARY, '-r', $code, $this->databaseFile, (string) $userId, (string) $id, $key, $mode], base_path());
            $process->setInput($stream);
            $process->setTimeout(20);
            $process->start();
            $this->workers[] = $process;
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
        $results = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $results[] = trim(str_replace('ready', '', $worker->getOutput()));
        }

        return $results;
    }

    public static function races(): array
    {
        return [['last_stock'], ['same_balance'], ['same_key']];
    }

    #[DataProvider('races')]
    public function test_concurrent_spending_cannot_oversell_overspend_or_double_deduct(string $case): void
    {
        $first = User::factory()->create();
        $second = $case === 'last_stock' ? User::factory()->create() : $first;
        $this->participation($first, 'completed');
        if ($second->id !== $first->id) {
            $this->participation($second, 'completed');
        }
        $reward = Reward::factory()->create(['stock_quantity' => 1]);
        $other = $case === 'same_balance' ? Reward::factory()->create(['stock_quantity' => 1]) : $reward;
        $key = (string) Str::uuid();
        $results = $this->race([[$first->id, $reward->id, $key], [$second->id, $other->id, $case === 'same_key' ? $key : (string) Str::uuid()]]);
        sort($results);
        $this->assertSame($case === 'same_key' ? ['ok', 'ok'] : ['ok', 'unavailable'], $results);
        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertSame(1, PointTransaction::where('type', 'reward_redemption')->count());
        $this->assertSame(-300, (int) PointTransaction::where('type', 'reward_redemption')->sum('amount'));
        $this->assertSame($case === 'same_balance' ? 1 : 0, (int) Reward::sum('stock_quantity'));
        $this->assertSame(300, UserVoucher::firstOrFail()->points_spent);
    }

    public function test_concurrent_completions_get_distinct_donation_numbers(): void
    {
        $user = User::factory()->create();
        $one = $this->participation($user, 'for_verification');
        $two = $this->participation($user, 'for_verification');
        $this->assertSame(['ok', 'ok'], $this->race([[$user->id, $one->id, 'unused'], [$user->id, $two->id, 'unused']], 'complete'));
        $this->assertSame([300, 350], PointTransaction::orderBy('amount')->pluck('amount')->all());
        $this->assertDatabaseCount('notifications', 2);
    }
}
