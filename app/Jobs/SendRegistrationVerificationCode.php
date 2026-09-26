<?php

namespace App\Jobs;

use App\Services\RegistrationVerificationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendRegistrationVerificationCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $requestId) {}

    public function handle(RegistrationVerificationService $service): void
    {
        $service->deliver($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RegistrationVerificationService::class)->invalidateDelivery($this->requestId);
    }
}
