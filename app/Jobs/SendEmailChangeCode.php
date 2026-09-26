<?php

namespace App\Jobs;

use App\Services\AccountSettingsService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendEmailChangeCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $requestId) {}

    public function handle(AccountSettingsService $service): void
    {
        $service->deliver($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AccountSettingsService::class)->invalidateDelivery($this->requestId);
    }
}
