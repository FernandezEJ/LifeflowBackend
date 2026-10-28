<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendImportantPush implements ShouldQueue
{
    use Queueable;

    // ========================================
    // IMPORTANT PUSH DELIVERY JOB
    // A bounded fan-out page and at most one per-device attempt. Durable target
    // state controls recovery; queue retry never authorizes replaying a send.
    // ========================================
    public int $tries = 1;

    // Keep below the database queue's 90-second retry_after (30-second margin).
    public int $timeout = 60;

    public function __construct(public int $notificationId) {}

    public function handle(PushNotificationService $push): void
    {
        try {
            $notification = Notification::find($this->notificationId);
            if ($notification) {
                $push->send($notification);
            }
        } catch (Throwable) {
            Log::error('Push coordinator unavailable; recover only durable unattempted work.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Push coordinator stopped; reconcile durable progress before any recovery.');
    }
}
