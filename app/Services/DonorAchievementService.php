<?php

namespace App\Services;

class DonorAchievementService
{
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
