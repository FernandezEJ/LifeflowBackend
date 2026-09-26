<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendImportantPush implements ShouldQueue
{
    use Queueable;

    // ========================================
    // IMPORTANT PUSH DELIVERY JOB
    // One delivery attempt avoids repeated phone alerts after ambiguous network
    // failures. The persistent claim also protects against duplicate queued jobs.
    // ========================================
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $notificationId) {}

    public function handle(PushNotificationService $push): void
    {
        $notification = Notification::find($this->notificationId);
        if ($notification) {
            $push->send($notification);
        }
    }
}
