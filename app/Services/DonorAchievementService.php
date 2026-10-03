<?php

namespace App\Services;

use App\Models\User;

class DonorAchievementService
{
    /** Canonical donor summary: completed participations plus unrepresented completed legacy records. */
    public function summary(User $user): array
    {
        $completed = $user->donationParticipations()->where('status', 'completed');
        $legacy = $user->donationRecords()->where('status', 'completed')
            ->where(fn ($query) => $query->whereNull('donation_participation_id')
                ->orWhereNotIn('donation_participation_id', (clone $completed)->select('id')))->count();
        $count = $completed->count() + $legacy;

        return ['total_donations' => $count, 'achievement' => $this->calculate($count)];
    }

    // ========================================
    // DONOR ACHIEVEMENT MILESTONES
    // Chooses the highest reached milestone from a completed donation count.
    // Labels are calculated, never written into a donor profile.
    // ========================================
    public function calculate(int $completed): array
    {
        return match (true) {
            $completed >= 10 => ['key' => 'gold_donor', 'label' => 'Gold Donor'],
            $completed >= 5 => ['key' => 'silver_donor', 'label' => 'Silver Donor'],
            $completed >= 3 => ['key' => 'bronze_donor', 'label' => 'Bronze Donor'],
            $completed >= 1 => ['key' => 'first_time_donor', 'label' => 'First-Time Donor'],
            default => ['key' => 'new_donor', 'label' => 'New Donor'],
        };
    }
}
