<?php

namespace App\Jobs;

use App\Services\PushDeliveryService;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDevicePush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'lifeflow-device-push:'.$this->deliveryId;
    }

    /**
     * Execute the job.
     */
    public function handle(PushDeliveryService $deliveries, PushNotificationService $transport): void
    {
        try {
            $deliveries->deliver($this->deliveryId, $transport);
        } catch (Throwable) {
            Log::error('Push delivery worker unavailable; durable state controls safe recovery.');
            $deliveries->interrupted($this->deliveryId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PushDeliveryService::class)->interrupted($this->deliveryId);
    }
}
