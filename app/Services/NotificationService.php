<?php

namespace App\Services;

use App\Jobs\SendImportantPush;
use App\Models\DonationOpportunity;
use App\Models\DonationParticipation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    // ========================================
    // IMPORTANT TRUSTED OUTCOMES ONLY
    // No donor endpoint sets completed/rejected. The completion award is
    // included in this existing message, without a second points push.
    // ========================================
    public function notifyParticipationOutcome(DonationParticipation $participation, ?int $pointsAwarded = null): ?Notification
    {
        if (! in_array($participation->status, ['completed', 'rejected', 'needs_revision'], true)) {
            return null;
        }
        $completed = $participation->status === 'completed';
        $revision = $participation->status === 'needs_revision';
        $reason = Str::limit(Str::squish((string) ($revision ? $participation->revision_reason : $participation->rejection_reason)), 240);
        $reviewMessage = $revision ? 'Please upload a corrected proof.' : 'Please open your activity to review your donation proof.';
        if ($reason !== '') {
            $reviewMessage .= ' '.$reason;
        }
        $eventKey = 'participation:'.$participation->id.':'.$participation->status;
        // A replacement proof starts a new review cycle; repeated saves of one proof stay quiet.
        if ($revision) {
            $eventKey .= ':'.hash('sha256', (string) $participation->proof_path);
        }

        return $this->createImportant($participation->user_id,
            $completed ? 'donation_completed' : ($revision ? 'donation_needs_revision' : 'donation_rejected'),
            $completed ? 'Donation verified' : ($revision ? 'Donation proof needs revision' : 'Donation proof needs attention'),
            $completed ? ($pointsAwarded !== null ? 'Your donation has been verified. '.$pointsAwarded.' points were added.' : 'Your donation has been verified successfully.') : $reviewMessage,
            ['participation_id' => $participation->id, 'opportunity_id' => $participation->donation_opportunity_id, 'points_awarded' => $completed ? $pointsAwarded : null],
            $eventKey);
    }

    // ========================================
    // FUTURE HIGH-PRIORITY ADMIN PUBLISH HOOK
    // The future trusted publish action calls this service, never a donor.
    // Draft, ordinary, expired and future-dated posts create no notification.
    // ========================================
    public function notifyImportantAnnouncement(DonationOpportunity $opportunity, bool $highPriority = false): void
    {
        if (! $highPriority || ! DonationOpportunity::active()->whereKey($opportunity->id)->exists()) {
            return;
        }
        User::query()->select('id')->chunkById(200, function ($users) use ($opportunity) {
            foreach ($users as $user) {
                $this->createImportant($user->id, 'admin_announcement', 'Important donation announcement',
                    'A new important donation opportunity is available. Open LifeFlow for details.',
                    ['opportunity_id' => $opportunity->id], 'opportunity:'.$opportunity->id.':important');
            }
        });
    }

    // ========================================
    // DATABASE EVENT DEDUPLICATION
    // Unique recipient/event keys handle concurrent retries. New records alone
    // enqueue push, and queue publication waits for the database commit.
    // ========================================
    public function notifyDonationReminder(DonationParticipation $participation): Notification
    {
        $date = $participation->opportunity->event_date->toDateString();

        return $this->createImportant($participation->user_id, 'donation_reminder', 'Your donation is tomorrow',
            'Rest well, eat properly, and stay hydrated. Open your activity for details.',
            ['participation_id' => $participation->id, 'opportunity_id' => $participation->donation_opportunity_id,
                'event_date' => $date, 'reminder_date' => DonationReminderService::calendarNow()->toDateString()],
            'participation:'.$participation->id.':reminder:'.$date);
    }

    private function createImportant(int $userId, string $type, string $title, string $message, array $data, string $eventKey): Notification
    {
        $notification = Notification::firstOrCreate(
            ['user_id' => $userId, 'event_key' => $eventKey],
            ['type' => $type, 'title' => $title, 'message' => $message, 'data' => $data]);
        if ($notification->wasRecentlyCreated) {
            DB::afterCommit(function () use ($notification): void {
                try {
                    SendImportantPush::dispatch($notification->id);
                } catch (\Throwable) {
                    // Queue failure cannot turn a committed outcome into an API failure.
                    Log::warning('Important notification push dispatch unavailable.');
                }
            });
        }

        return $notification;
    }
}
