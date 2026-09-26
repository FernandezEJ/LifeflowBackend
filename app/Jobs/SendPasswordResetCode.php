<?php

namespace App\Jobs;

use App\Services\PasswordResetService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPasswordResetCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $email, public string $requestId) {}

    public function handle(PasswordResetService $service): void
    {
        $service->deliver($this->email, $this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Password reset worker failed. Check the password-resets queue and mail configuration.');
        app(PasswordResetService::class)->invalidate($this->requestId);
    }
}
