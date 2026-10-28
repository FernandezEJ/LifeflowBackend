<?php

namespace Tests\Feature;

use App\Jobs\SendDevicePush;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\PushDeliveryService;
use App\Services\PushNotificationService;
use App\Services\SessionTokenPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PushDeliveryTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->freezeTime();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->fakeHttp(['https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/synthetic/messages/accepted'])]);
        config(['services.fcm.enabled' => true, 'services.fcm.project_id' => 'synthetic-project', 'services.fcm.credentials' => __FILE__]);
        $this->owner = User::factory()->create();
    }

    private function transport(): PushNotificationService
    {
        return new class extends PushNotificationService
        {
            public bool $preflightUnavailable = false;

            protected function accessToken(): string
            {
                if ($this->preflightUnavailable) {
                    throw new \RuntimeException('sensitive-provider-canary');
                }

                return 'synthetic-oauth';
            }
        };
    }

    private function fakeHttp(callable|array $callbacks): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callbacks);
    }

    private function device(): DeviceToken
    {
        $suffix = DeviceToken::max('id') + 1;
        $token = 'synthetic-device-'.$suffix;

        return DeviceToken::create(['user_id' => $this->owner->id,
            'personal_access_token_id' => $this->owner->createToken('mobile', ['*'], SessionTokenPolicy::expiresFor($this->owner))->accessToken->id,
            'token' => $token, 'token_hash' => hash('sha256', $token), 'platform' => 'android', 'provider' => 'fcm', 'last_seen_at' => now()]);
    }

    private function notice(): Notification
    {
        return $this->owner->notifications()->create(['type' => 'donation_completed', 'title' => 'Donation verified',
            'message' => 'Your donation was verified.', 'data' => ['participation_id' => 123], 'event_key' => 'r4:'.Notification::count()]);
    }

    private function drain(PushNotificationService $transport): void
    {
        foreach (DB::table(PushDeliveryService::TABLE)->where('state', 'pending')->orderBy('id')->pluck('id') as $id) {
            (new SendDevicePush($id))->handle(app(PushDeliveryService::class), $transport);
        }
    }

    public function test_multi_device_progress_duplicate_jobs_payload_and_inbox_privacy(): void
    {
        foreach (range(1, 3) as $unused) {
            $this->device();
        }
        $notice = $this->notice();
        $transport = $this->transport();
        $transport->send($notice);
        Http::assertSentCount(1);
        Queue::assertPushed(SendDevicePush::class, 2);
        $this->assertDatabaseCount(PushDeliveryService::TABLE, 3);
        $this->drain($transport);
        $this->drain($transport);
        $transport->send($notice->fresh());
        Http::assertSentCount(3);
        $this->assertSame(3, DB::table(PushDeliveryService::TABLE)->where('state', 'accepted')->count());
        $this->assertSame('sent', $notice->fresh()->push_status);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('point_transactions', 0);
        Http::assertSent(fn ($request) => $request['message']['data']['notification_id'] === (string) $notice->id
            && $request['message']['android']['notification']['tag'] === 'lifeflow-'.$notice->id
            && $request['message']['android']['notification']['channel_id'] === 'important');
        foreach (array_keys($notice->fresh()->toArray()) as $field) {
            $this->assertFalse(str_starts_with($field, 'push_'));
        }
        $journal = json_encode(DB::table(PushDeliveryService::TABLE)->get());
        foreach (['synthetic-device-', 'synthetic-oauth', $this->owner->email, 'participation_id', 'Donation verified'] as $private) {
            $this->assertStringNotContainsString($private, $journal);
        }
    }

    public static function outcomes(): array
    {
        return [['accepted', 200], ['ambiguous', null], ['ambiguous', 201], ['rejected', 400], ['rejected', 401],
            ['rejected', 403], ['rejected', 404], ['rejected', 429], ['rejected', 500], ['rejected', 503]];
    }

    #[DataProvider('outcomes')]
    public function test_provider_outcome_is_terminal_without_automatic_replay(string $state, ?int $status): void
    {
        $this->device();
        $notice = $this->notice();
        $calls = 0;
        Log::spy();
        $this->fakeHttp(function () use (&$calls, $status) {
            $calls++;

            return $status === null ? Http::failedConnection() : Http::response($status === 200 ? ['name' => 'accepted'] : ['private' => 'sensitive-provider-canary'], $status);
        });
        $transport = $this->transport();
        $transport->send($notice);
        $id = DB::table(PushDeliveryService::TABLE)->value('id');
        foreach (range(1, 3) as $unused) {
            (new SendDevicePush($id))->handle(app(PushDeliveryService::class), $transport);
            app(PushDeliveryService::class)->recover($transport);
            $transport->send($notice->fresh());
        }
        $this->assertSame(1, $calls);
        $this->assertDatabaseHas(PushDeliveryService::TABLE, ['id' => $id, 'state' => $state]);
        if ($state !== 'accepted') {
            Log::shouldHaveReceived('warning')->once()->with('Push delivery requires monitoring.', ['state' => $state]);
        }
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_partial_fanout_recovers_only_the_unattempted_device(): void
    {
        foreach (range(1, 3) as $unused) {
            $this->device();
        }
        $notice = $this->notice();
        $transport = $this->transport();
        $transport->send($notice);
        $ids = DB::table(PushDeliveryService::TABLE)->orderBy('id')->pluck('id');
        $this->fakeHttp(fn () => Http::failedConnection());
        app(PushDeliveryService::class)->deliver($ids[1], $transport);
        $this->travel(6)->minutes();
        $counts = app(PushDeliveryService::class)->recover($transport);
        $this->assertSame(1, $counts['device_jobs']);
        $this->fakeHttp(['*' => Http::response(['name' => 'accepted'])]);
        $this->drain($transport);
        $this->assertSame(['accepted', 'ambiguous', 'accepted'], DB::table(PushDeliveryService::TABLE)->orderBy('id')->pluck('state')->all());
        $this->assertSame('ambiguous', $notice->fresh()->push_status);
    }

    public function test_preflight_failure_is_confirmed_unattempted_and_can_recover(): void
    {
        $this->device();
        $notice = $this->notice();
        $transport = $this->transport();
        $transport->preflightUnavailable = true;
        $transport->send($notice);
        Http::assertNothingSent();
        $this->assertDatabaseHas(PushDeliveryService::TABLE, ['state' => 'pending', 'attempted_at' => null]);
        $transport->preflightUnavailable = false;
        $this->drain($transport);
        Http::assertSentCount(1);
        $this->assertSame('sent', $notice->fresh()->push_status);
    }

    public function test_fanout_pages_are_bounded_and_new_devices_do_not_extend_the_high_water_mark(): void
    {
        foreach (range(1, 55) as $unused) {
            $this->device();
        }
        $notice = $this->notice();
        $transport = $this->transport();
        $transport->send($notice);
        $this->assertDatabaseCount(PushDeliveryService::TABLE, 50);
        $this->assertNull($notice->fresh()->push_fanout_completed_at);
        $this->assertSame(55, $notice->fresh()->push_fanout_max_device_id);
        $this->device();
        $transport->send($notice->fresh());
        $this->assertDatabaseCount(PushDeliveryService::TABLE, 55);
        $this->assertNotNull($notice->fresh()->push_fanout_completed_at);
        $this->drain($transport);
        $this->assertSame(55, DB::table(PushDeliveryService::TABLE)->where('state', 'accepted')->count());
        Http::assertSentCount(55);
    }

    public function test_legacy_attempts_are_never_backfilled_or_replayed(): void
    {
        $this->device();
        $notice = $this->notice();
        $notice->update(['push_attempted_at' => now()->subMinute(), 'push_status' => 'sending']);
        $transport = $this->transport();
        $transport->send($notice);
        $counts = app(PushDeliveryService::class)->recover($transport);
        $this->assertSame(0, $counts['fanout_jobs']);
        $this->assertDatabaseCount(PushDeliveryService::TABLE, 0);
        Http::assertNothingSent();
        $this->assertSame('sending', $notice->fresh()->push_status);
    }

    public static function freshness(): array
    {
        return [[86399, true], [86400, false], [86401, false]];
    }

    #[DataProvider('freshness')]
    public function test_send_freshness_is_absolute_from_the_inbox_timestamp(int $age, bool $send): void
    {
        $this->device();
        $notice = $this->notice();
        $notice->update(['created_at' => now()->subSeconds($age)]);
        $this->transport()->send($notice);
        Http::assertSentCount($send ? 1 : 0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function invalidTargets(): array
    {
        return [['deleted-device'], ['transferred'], ['rotated-token'], ['revoked-session'], ['expired-session'], ['deactivated'], ['unverified'], ['staff']];
    }

    #[DataProvider('invalidTargets')]
    public function test_pending_work_rechecks_target_identity_owner_and_absolute_session_age(string $case): void
    {
        $device = $this->device();
        $transport = $this->transport();
        $transport->preflightUnavailable = true;
        $transport->send($this->notice());
        match ($case) {
            'deleted-device' => $device->delete(),
            'transferred' => $device->update(['user_id' => User::factory()->create()->id]),
            'rotated-token' => $device->update(['token_hash' => hash('sha256', 'rotated')]),
            'revoked-session' => $device->accessToken()->delete(),
            'expired-session' => DB::table('personal_access_tokens')->where('id', $device->personal_access_token_id)->update(['expires_at' => null, 'created_at' => now()->subDays(31)]),
            'deactivated' => $this->owner->forceFill(['deactivated_at' => now()])->save(),
            'unverified' => $this->owner->forceFill(['email_verified_at' => null])->save(),
            'staff' => $this->owner->forceFill(['role' => 'admin'])->save(),
        };
        $transport->preflightUnavailable = false;
        $this->drain($transport);
        $this->assertSame(0, DB::table(PushDeliveryService::TABLE)->whereIn('state', ['pending', 'attempting', 'accepted'])->count());
        Http::assertNothingSent();
    }

    public function test_recovery_is_bounded_and_stale_attempts_are_ambiguous_not_requeued(): void
    {
        $this->device();
        $transport = $this->transport();
        $transport->preflightUnavailable = true;
        foreach (range(1, 105) as $unused) {
            $transport->send($this->notice());
        }
        $id = DB::table(PushDeliveryService::TABLE)->value('id');
        DB::table(PushDeliveryService::TABLE)->where('id', $id)->update(['state' => 'attempting', 'attempted_at' => now()->subMinutes(6)]);
        $counts = app(PushDeliveryService::class)->recover($transport);
        $this->assertSame(1, $counts['ambiguous']);
        $this->assertSame(100, $counts['device_jobs'] + $counts['fanout_jobs']);
        $this->assertDatabaseHas(PushDeliveryService::TABLE, ['id' => $id, 'state' => 'ambiguous']);
        (new SendDevicePush($id))->handle(app(PushDeliveryService::class), $transport);
        Http::assertNothingSent();
    }

    public function test_worker_budget_and_unique_dispatch_are_below_reservation(): void
    {
        $job = new SendDevicePush(1);
        $this->assertSame(1, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(300, $job->uniqueFor);
        $this->assertGreaterThanOrEqual($job->timeout + 30, config('queue.connections.database.retry_after'));
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_accepted_plus_rejected_is_partial_and_neither_target_is_replayed(): void
    {
        $this->device();
        $this->device();
        $notice = $this->notice();
        $transport = $this->transport();
        $transport->send($notice);
        $this->fakeHttp(['*' => Http::response([], 429)]);
        $this->drain($transport);
        $this->assertSame('partial', $notice->fresh()->push_status);
        $this->assertSame(['accepted', 'rejected'], DB::table(PushDeliveryService::TABLE)->orderBy('id')->pluck('state')->all());
        $this->drain($transport);
        Http::assertSentCount(1);
    }

    public function test_legacy_stuck_status_is_monitored_as_unknown_without_journal_backfill(): void
    {
        $this->device();
        $notice = $this->notice();
        $notice->update(['push_status' => 'sending', 'push_attempted_at' => now()->subMinutes(6)]);
        $result = app(PushDeliveryService::class)->recover($this->transport());
        $this->assertSame(1, $result['legacy_ambiguous']);
        $this->assertSame('ambiguous', $notice->fresh()->push_status);
        $this->assertDatabaseCount(PushDeliveryService::TABLE, 0);
        Http::assertNothingSent();
    }

    public function test_disabling_fcm_preserves_unattempted_journal_and_inbox_without_a_send(): void
    {
        $this->device();
        $transport = $this->transport();
        $transport->preflightUnavailable = true;
        $transport->send($this->notice());
        config(['services.fcm.enabled' => false]);
        $this->drain($transport);
        $counts = app(PushDeliveryService::class)->recover($transport);
        $this->assertSame(0, $counts['device_jobs'] + $counts['fanout_jobs']);
        $this->assertDatabaseHas(PushDeliveryService::TABLE, ['state' => 'pending', 'attempted_at' => null]);
        $this->assertDatabaseCount('notifications', 1);
        Http::assertNothingSent();
    }

    public function test_dispatch_outage_keeps_unattempted_work_recoverable_without_replaying_accepted_work(): void
    {
        $this->device();
        $this->device();
        $transport = $this->transport();
        $bus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('sensitive-provider-canary'));
        try {
            $transport->send($this->notice());
            $this->assertSame(['accepted', 'pending'], DB::table(PushDeliveryService::TABLE)->orderBy('id')->pluck('state')->all());
            $this->assertSame(0, app(PushDeliveryService::class)->recover($transport)['device_jobs']);
        } finally {
            Bus::swap($bus);
        }
        $this->travel(6)->minutes();
        $this->assertSame(1, app(PushDeliveryService::class)->recover($transport)['device_jobs']);
        $this->drain($transport);
        $this->assertSame(2, DB::table(PushDeliveryService::TABLE)->where('state', 'accepted')->count());
        Http::assertSentCount(2);
    }

    public function test_slow_provider_overlap_cannot_start_a_second_send(): void
    {
        $this->device();
        $transport = $this->transport();
        $calls = 0;
        $this->fakeHttp(function () use (&$calls, $transport) {
            $calls++;
            $id = DB::table(PushDeliveryService::TABLE)->value('id');
            usleep(20000);
            (new SendDevicePush($id))->handle(app(PushDeliveryService::class), $transport);
            $this->assertDatabaseHas(PushDeliveryService::TABLE, ['id' => $id, 'state' => 'attempting']);

            return Http::response(['name' => 'accepted']);
        });
        $transport->send($this->notice());
        $this->assertSame(1, $calls);
        $this->assertDatabaseHas(PushDeliveryService::TABLE, ['state' => 'accepted']);
    }

    public function test_pending_queued_work_is_not_dispatched_twice_until_its_unique_reservation_expires(): void
    {
        $this->device();
        $this->device();
        $transport = $this->transport();
        $transport->send($this->notice());
        Queue::assertPushed(SendDevicePush::class, 1);
        $this->assertSame(0, app(PushDeliveryService::class)->recover($transport)['device_jobs']);
        Queue::assertPushed(SendDevicePush::class, 1);
        $this->travel(6)->minutes();
        $this->assertSame(1, app(PushDeliveryService::class)->recover($transport)['device_jobs']);
        Queue::assertPushed(SendDevicePush::class, 2);
        $this->drain($transport);
        $this->drain($transport);
        Http::assertSentCount(2);
    }

    public static function interruptions(): array
    {
        return [['before'], ['after']];
    }

    #[DataProvider('interruptions')]
    public function test_killed_real_worker_preserves_pending_or_ambiguous_boundary_and_blocks_duplicate_worker(string $phase): void
    {
        $this->device();
        $transport = $this->transport();
        $transport->preflightUnavailable = true;
        $notice = $this->notice();
        $transport->send($notice);
        $id = DB::table(PushDeliveryService::TABLE)->value('id');
        $path = tempnam(sys_get_temp_dir(), 'lifeflow-r4-push-');
        unlink($path);
        $workers = [];
        try {
            DB::connection()->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $path)."'");
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
            $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$argv[1],'database.connections.sqlite.busy_timeout'=>5000,'cache.default'=>'array','services.fcm.project_id'=>'synthetic-project']);
            \Illuminate\Support\Facades\DB::purge('sqlite');
            \Illuminate\Support\Facades\Mail::fake();
            \Illuminate\Support\Facades\Queue::fake();
            \Illuminate\Support\Carbon::setTestNow($argv[3]);
            \Illuminate\Support\Facades\Http::preventStrayRequests();
            $sender = new class($argv[4]) extends \App\Services\PushNotificationService {
                public function __construct(public string $phase) {}
                public function configured(): bool { return true; }
                public function authorization(): string {
                    if ($this->phase === 'before') { echo "preflight-ready\n"; fflush(STDOUT); fgets(STDIN); }
                    return 'synthetic-oauth';
                }
            };
            \Illuminate\Support\Facades\Http::fake(['https://fcm.googleapis.com/*'=>function () {
                echo "provider-ready\n"; fflush(STDOUT); fgets(STDIN);
                return \Illuminate\Support\Facades\Http::response(['name'=>'accepted']);
            }]);
            app(\App\Services\PushDeliveryService::class)->deliver((int)$argv[2], $sender);
            echo "finished\n";
            PHP;
            $stream = new InputStream;
            $worker = new Process([PHP_BINARY, '-r', $code, $path, (string) $id, now()->toDateTimeString(), $phase], base_path());
            $worker->setInput($stream);
            $worker->setTimeout(25);
            $worker->start();
            $workers[] = $worker;
            $ready = $phase === 'before' ? 'preflight-ready' : 'provider-ready';
            $deadline = microtime(true) + 15;
            while (! str_contains($worker->getOutput(), $ready) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString($ready, $worker->getOutput());
            if ($phase === 'after') {
                $duplicate = new Process([PHP_BINARY, '-r', $code, $path, (string) $id, now()->toDateTimeString(), 'after'], base_path());
                $duplicate->setTimeout(15);
                $workers[] = $duplicate;
                $duplicate->run();
                $this->assertSame(0, $duplicate->getExitCode());
                $this->assertStringNotContainsString('provider-ready', $duplicate->getOutput());
            }
            $worker->stop(0);
            $this->travel(6)->minutes();
            $transport->preflightUnavailable = false;
            app(PushDeliveryService::class)->recover($transport);
            $this->assertDatabaseHas(PushDeliveryService::TABLE, ['id' => $id, 'state' => $phase === 'before' ? 'pending' : 'ambiguous']);
            $this->drain($transport);
            Http::assertSentCount($phase === 'before' ? 1 : 0);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertNull(Notification::findOrFail($notice->id)->read_at);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach ([$path, $path.'-wal', $path.'-shm'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }

    }
}
