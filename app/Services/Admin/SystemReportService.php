<?php

namespace App\Services\Admin;

use App\Models\DonationOpportunity;
use App\Models\EligibilityAssessment;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SystemReportService
{
    private const RECORD_LIMIT = 20;

    public function monthly(array $filters): array
    {
        $today = now('Asia/Manila');
        $month = Carbon::create((int) ($filters['year'] ?? $today->year), (int) ($filters['month'] ?? $today->month), 1, 0, 0, 0, 'Asia/Manila');
        $from = $month->copy()->utc();
        $until = $month->copy()->addMonth()->utc();
        $donors = User::where('role', 'donor')->whereNull('deactivated_at');
        $latest = EligibilityAssessment::select('result')->whereColumn('user_id', 'users.id')
            ->orderByDesc('assessed_at')->orderByDesc('id')->limit(1);
        $counts = DB::query()->fromSub((clone $donors)->select('id')->selectSub($latest, 'latest_result'), 'latest_assessments')
            ->selectRaw('latest_result, COUNT(*) as total')->groupBy('latest_result')->pluck('total', 'latest_result');
        $participations = DB::table('donation_participations as participation')->join('users', 'users.id', '=', 'participation.user_id')
            ->where('users.role', 'donor')->where('participation.joined_at', '>=', $from)->where('participation.joined_at', '<', $until)
            ->selectRaw('participation.status, COUNT(*) as total')->groupBy('participation.status')->pluck('total', 'status');
        $completed = $this->completed($from, $until);
        $blood = (clone $completed)->join('donor_profiles as profile', 'profile.user_id', '=', 'completed.user_id')
            ->selectRaw('profile.blood_type, COUNT(*) as total')->groupBy('profile.blood_type')->pluck('total', 'blood_type');
        $weekly = $this->weekly($completed, $month);
        $purchases = $this->purchases($from, $until);
        $rewards = (clone $purchases)->selectRaw('COUNT(*) as total, COALESCE(SUM(-ledger.amount), 0) as points')->first();
        $top = (clone $purchases)->selectRaw('vouchers.reward_id, COUNT(*) as total')
            ->groupBy('vouchers.reward_id')->orderByDesc('total')->orderBy('vouchers.reward_id')->first();
        $topRow = $top ? (clone $purchases)->where('vouchers.reward_id', $top->reward_id)
            ->orderByDesc('vouchers.id')->select(['vouchers.reward_snapshot', 'rewards.name as reward_name'])->first() : null;
        $pointsIssued = DB::table('point_transactions')->where('amount', '>', 0)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)->sum('amount');

        return [
            'period' => $month->format('Y-m'), 'generated_at' => now()->toISOString(),
            'data' => [
                'donor_summary' => ['total' => (int) $counts->sum(), 'eligible' => (int) ($counts['eligible'] ?? 0),
                    'not_eligible' => (int) ($counts['not_eligible'] ?? 0) + (int) ($counts['temporarily_ineligible'] ?? 0)],
                'donation_summary' => ['total' => (int) $participations->sum(), 'completed' => array_sum(array_column($weekly, 'donations')),
                    'for_verification' => (int) ($participations['for_verification'] ?? 0),
                    'needs_revision' => (int) ($participations['needs_revision'] ?? 0), 'rejected' => (int) ($participations['rejected'] ?? 0)],
                'rewards_summary' => ['points_issued' => (int) $pointsIssued, 'most_redeemed_reward' => $topRow ? $this->rewardName($topRow) : null,
                    'total_redemptions' => (int) $rewards->total, 'points_redeemed' => (int) $rewards->points],
                'weekly_donations' => $weekly,
                'blood_types' => array_map(fn (string $type): array => ['name' => $type, 'value' => (int) ($blood[$type] ?? 0)],
                    ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
                'completed_donations' => $this->completedRows($completed),
                'reward_redemptions' => $this->rewardRows($purchases),
                'notes' => 'Donor Summary uses all current active registered donor accounts and their latest saved assessments, regardless of the selected period. '
                    .'Blood Distribution counts completed donation events during the selected month using current saved blood types; missing or unrecognized blood types are omitted. '
                    .'Total Participations and review statuses use joins during the selected month and current statuses. Completed donations use verification dates, including deduplicated legacy history; undated completions are excluded. '
                    .'Rewards count owned voucher purchases with matching points debits, as in Rewards Analytics. Points issued are positive ledger credits. All monthly activity uses Philippine time (UTC+8). '
                    .'Tables show up to '.self::RECORD_LIMIT.' recent records each; summary and chart totals cover the entire month.',
            ],
        ];
    }

    /** Same-owner completed legacy deduplication matches DonorAchievementService, across all dates. */
    private function completed(Carbon $from, Carbon $until): Builder
    {
        $participations = DB::table('donation_participations as participation')->join('users', 'users.id', '=', 'participation.user_id')
            ->where('users.role', 'donor')->where('participation.status', 'completed')
            ->where('participation.verified_at', '>=', $from)->where('participation.verified_at', '<', $until)
            ->selectRaw("participation.id, participation.user_id, participation.verified_at, participation.donation_opportunity_id, participation.source_type, NULL as location, 'participation' as kind");
        $legacy = DB::table('donation_records as record')->join('users', 'users.id', '=', 'record.user_id')
            ->where('users.role', 'donor')->where('record.status', 'completed')
            ->where('record.verified_at', '>=', $from)->where('record.verified_at', '<', $until)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('donation_participations as represented')
                ->whereColumn('represented.id', 'record.donation_participation_id')->whereColumn('represented.user_id', 'record.user_id')
                ->where('represented.status', 'completed'))
            ->selectRaw("record.id, record.user_id, record.verified_at, NULL as donation_opportunity_id, NULL as source_type, record.location, 'legacy' as kind");

        return DB::query()->fromSub($participations->unionAll($legacy), 'completed');
    }

    private function weekly(Builder $completed, Carbon $month): array
    {
        $case = 'CASE';
        $boundaries = [];
        for ($week = 1; $week < 5; $week++) {
            $case .= ' WHEN verified_at < ? THEN '.$week;
            $boundaries[] = $month->copy()->addDays(7 * $week)->utc();
        }
        $counts = (clone $completed)->selectRaw($case.' ELSE 5 END as week, COUNT(*) as total', $boundaries)
            ->groupBy('week')->pluck('total', 'week');

        return array_map(fn (int $week): array => ['week' => 'Week '.$week, 'donations' => (int) ($counts[$week] ?? 0)], range(1, 5));
    }

    private function completedRows(Builder $completed): array
    {
        return (clone $completed)->join('users', 'users.id', '=', 'completed.user_id')
            ->leftJoin('donor_profiles as profile', 'profile.user_id', '=', 'completed.user_id')
            ->leftJoin('donation_opportunities as opportunity', 'opportunity.id', '=', 'completed.donation_opportunity_id')
            ->select(['completed.id', 'completed.kind', 'completed.verified_at', 'completed.source_type', 'completed.location',
                'users.name as donor', 'profile.blood_type', 'opportunity.title as event'])
            ->orderByDesc('completed.verified_at')->orderBy('completed.kind')->orderByDesc('completed.id')->limit(self::RECORD_LIMIT)->get()
            ->map(fn ($row): array => ['id' => $row->kind.'-'.$row->id, 'donor' => $row->donor, 'bloodType' => $row->blood_type,
                'event' => $row->event ?? ($row->source_type === 'red_cross_dagupan' ? DonationOpportunity::redCrossDagupan()['title'] : ($row->location ?? 'Unavailable event')),
                'date' => Carbon::parse($row->verified_at, 'UTC')->timezone('Asia/Manila')->toDateString()])->all();
    }

    /** RewardAnalyticsService's canonical purchase join, aggregated in SQL for monthly reporting. */
    private function purchases(Carbon $from, Carbon $until): Builder
    {
        return DB::table('user_vouchers as vouchers')->join('point_transactions as ledger', function ($join): void {
            $join->on('ledger.source_id', '=', 'vouchers.id')->on('ledger.user_id', '=', 'vouchers.user_id')
                ->where('ledger.source_type', 'user_voucher')->where('ledger.type', 'reward_redemption')->where('ledger.amount', '<', 0);
        })->leftJoin('users', 'users.id', '=', 'vouchers.user_id')->leftJoin('rewards', 'rewards.id', '=', 'vouchers.reward_id')
            ->where('ledger.created_at', '>=', $from)->where('ledger.created_at', '<', $until);
    }

    private function rewardName(object $row): string
    {
        $snapshot = json_decode($row->reward_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);

        return $snapshot['name'] ?? $row->reward_name ?? 'Unavailable reward';
    }

    private function rewardRows(Builder $purchases): array
    {
        return (clone $purchases)->select(['vouchers.id', 'vouchers.reward_snapshot', 'rewards.name as reward_name',
            'users.name as donor', 'ledger.amount', 'ledger.created_at'])
            ->orderByDesc('ledger.created_at')->orderByDesc('vouchers.id')->orderByDesc('ledger.id')->limit(self::RECORD_LIMIT)->get()
            ->map(fn ($row): array => ['id' => $row->id, 'donor' => $row->donor ?? 'Unavailable donor', 'voucher' => $this->rewardName($row),
                'pointsUsed' => -(int) $row->amount, 'date' => Carbon::parse($row->created_at, 'UTC')->timezone('Asia/Manila')->toDateString()])->all();
    }
}
