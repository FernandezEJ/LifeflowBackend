<?php

namespace App\Services;

use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DonorAchievementService
{
    /** Canonical donor summary: completed participations plus unrepresented completed legacy records. */
    public function summary(User $user): array
    {
        return $this->summaries([$user->id])[$user->id];
    }

    /**
     * The same completed-only summary in one grouped query, regardless of donor count.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{total_donations: int, achievement: array{key: string, label: string}}>
     */
    public function summaries(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $counts = $this->completedCountsQuery($userIds)->pluck('total', 'user_id');

        $summaries = [];
        foreach ($userIds as $id) {
            $count = (int) ($counts[$id] ?? 0);
            $summaries[$id] = ['total_donations' => $count, 'achievement' => $this->calculate($count)];
        }

        return $summaries;
    }

    /** @param list<int>|null $userIds Limit counting to these donors, or rank the full population. */
    public function completedCountsQuery(?array $userIds = null): Builder
    {
        $completed = DonationParticipation::select('user_id')->where('status', 'completed');
        $legacy = DonationRecord::select('user_id')->where('status', 'completed')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('donation_participations')
                ->whereColumn('donation_participations.id', 'donation_records.donation_participation_id')
                ->whereColumn('donation_participations.user_id', 'donation_records.user_id')
                ->where('donation_participations.status', 'completed'));
        if ($userIds !== null) {
            $completed->whereIn('user_id', $userIds);
            $legacy->whereIn('user_id', $userIds);
        }

        return DB::query()->fromSub($completed->toBase()->unionAll($legacy->toBase()), 'completed_history')
            ->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id');
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
