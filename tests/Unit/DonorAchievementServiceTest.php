<?php

namespace Tests\Unit;

use App\Services\DonorAchievementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DonorAchievementServiceTest extends TestCase
{
    // ========================================
    // MILESTONE BOUNDARIES
    // Tests each milestone and the counts between milestones.
    // ========================================
    public static function milestones(): array
    {
        return [[0, 'new_donor', 'New Donor'], [1, 'first_time_donor', 'First-Time Donor'],
            [2, 'first_time_donor', 'First-Time Donor'], [3, 'bronze_donor', 'Bronze Donor'],
            [4, 'bronze_donor', 'Bronze Donor'], [5, 'silver_donor', 'Silver Donor'],
            [9, 'silver_donor', 'Silver Donor'], [10, 'gold_donor', 'Gold Donor'], [20, 'gold_donor', 'Gold Donor']];
    }

    #[DataProvider('milestones')]
    public function test_milestone(int $count, string $key, string $label): void
    {
        $this->assertSame(compact('key', 'label'), (new DonorAchievementService)->calculate($count));
    }
}
