<?php

namespace App\Jobs;

use App\Services\DonorLoginService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendDonorLoginCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $requestId, public string $mobile) {}

    /**
     * Execute the job.
     */
    public function handle(DonorLoginService $service): void
    {
        $service->deliver($this->requestId, $this->mobile);
    }

    public function failed(?Throwable $exception): void
    {
        app(DonorLoginService::class)->invalidateDelivery($this->requestId);
    }
}
