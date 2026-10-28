<?php

namespace App\Console\Commands;

use App\Services\PushDeliveryService;
use App\Services\PushNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('notifications:recover-push')]
#[Description('Resume only unattempted bounded push work and classify stalled attempts without replay')]
class RecoverNotificationPushes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PushDeliveryService $deliveries, PushNotificationService $transport): int
    {
        $lock = Cache::lock('lifeflow:push-recovery', 120);
        try {
            if (! $lock->get()) {
                $this->info('Push recovery is already running.');

                return self::SUCCESS;
            }
            $counts = $deliveries->recover($transport);
            $this->info('Ambiguous='.$counts['ambiguous'].' legacy_ambiguous='.$counts['legacy_ambiguous'].' fanout_jobs='.$counts['fanout_jobs'].' device_jobs='.$counts['device_jobs']);

            return self::SUCCESS;
        } catch (Throwable) {
            Log::error('Push recovery unavailable; inspect aggregate backlog and queue/cache health.');
            $this->error('Push recovery failed. No attempted send should be manually replayed.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
