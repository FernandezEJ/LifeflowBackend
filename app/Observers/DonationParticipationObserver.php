<?php

namespace App\Observers;

use App\Models\DonationParticipation;
use App\Services\NotificationService;
use App\Services\PointsService;

class DonationParticipationObserver
{
    // ========================================
    // AUTOMATIC TRUSTED STATUS CHANGES
    // Ordinary saves, joins, uploads and cancellation produce no notification.
    // The notification joins the verification transaction; push waits for commit.
    // Future admin code must use an Eloquent save, not a bulk SQL update.
    // ========================================
    // Trusted imports created as completed use the same atomic award lifecycle.
    public function created(DonationParticipation $participation): void
    {
        if ($participation->status === 'completed') {
            $award = app(PointsService::class)->awardDonation($participation);
            app(NotificationService::class)->notifyParticipationOutcome($participation, $award?->amount);
        }
    }

    public function updated(DonationParticipation $participation): void
    {
        if ($participation->wasChanged('status')) {
            $award = app(PointsService::class)->awardDonation($participation);
            app(NotificationService::class)->notifyParticipationOutcome($participation, $award?->amount);
        }
    }
}
