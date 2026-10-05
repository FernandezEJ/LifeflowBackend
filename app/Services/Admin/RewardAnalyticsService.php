<?php

namespace App\Services\Admin;

use App\Models\Reward;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RewardAnalyticsService
{
    /** Successful purchases have an owned voucher and its matching negative ledger entry. */
    private function purchases(): Builder
    {
        return DB::table('user_vouchers as vouchers')->join('point_transactions as ledger', function ($join): void {
            $join->on('ledger.source_id', '=', 'vouchers.id')->on('ledger.user_id', '=', 'vouchers.user_id')
                ->where('ledger.source_type', 'user_voucher')->where('ledger.type', 'reward_redemption')->where('ledger.amount', '<', 0);
        })->leftJoin('users', 'users.id', '=', 'vouchers.user_id')
            ->leftJoin('rewards', 'rewards.id', '=', 'vouchers.reward_id');
    }

    private function rows(Builder $query): Collection
    {
        return $query->select(['vouchers.id', 'vouchers.reward_id', 'vouchers.reward_snapshot', 'vouchers.status',
            'vouchers.expires_at', 'ledger.created_at', 'ledger.amount', 'users.name as donor',
            'rewards.name as reward_name', 'rewards.voucher_value', 'rewards.category'])->orderByDesc('vouchers.id')->get()->map(function ($row): array {
                $snapshot = json_decode($row->reward_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $date = Carbon::parse($row->created_at, 'UTC')->timezone(config('app.calendar_timezone'));

                return ['id' => $row->id, 'reward_id' => $row->reward_id, 'donor' => $row->donor ?? 'Unavailable donor',
                    'reward' => $snapshot['name'] ?? $row->reward_name, 'voucherValue' => (float) ($snapshot['voucher_value'] ?? $row->voucher_value),
                    'category' => $row->category ?? 'grocery',
                    'pointsUsed' => -(int) $row->amount, 'created_at' => $date->toISOString(), 'redeemedDate' => $date->toDateString(),
                    'status' => $row->status === 'active' && $row->expires_at && Carbon::parse($row->expires_at)->lte(now()) ? 'redeemed' : $row->status];
            });
    }

    public function redemptions(array $filters): array
    {
        $rows = $this->rows($this->purchases())->filter(function (array $row) use ($filters): bool {
            $search = mb_strtolower(trim($filters['search'] ?? ''));

            return ($search === '' || str_contains(mb_strtolower($row['donor'].' '.$row['reward']), $search))
                && (($filters['category'] ?? 'all') === 'all' || $row['category'] === $filters['category'])
                && (! isset($filters['amount']) || $row['voucherValue'] === (float) $filters['amount'])
                && (! isset($filters['month']) || (int) substr($row['redeemedDate'], 5, 2) === (int) $filters['month'])
                && (! isset($filters['year']) || (int) substr($row['redeemedDate'], 0, 4) === (int) $filters['year'])
                && (($filters['status'] ?? 'all') === 'all' || $row['status'] === $filters['status']);
        });

        return ['data' => $rows->values()->all()];
    }

    public function summary(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now(config('app.calendar_timezone'))->year);
        $month = isset($filters['month']) ? (int) $filters['month'] : null;
        $start = Carbon::create($year, $month ?? 1, 1, 0, 0, 0, config('app.calendar_timezone'));
        $end = $month ? $start->copy()->addMonth() : $start->copy()->addYear();
        $rows = $this->rows($this->purchases()->where('ledger.created_at', '>=', $start->copy()->utc())->where('ledger.created_at', '<', $end->copy()->utc()));
        $trend = [];
        // Optional monthly API requests retain their aggregate bar for compatibility.
        foreach ($month ? [$month] : range(1, 12) as $number) {
            $period = sprintf('%04d-%02d', $year, $number);
            $trend[] = ['period' => $period, 'redemptions' => $rows->filter(fn (array $row): bool => str_starts_with($row['redeemedDate'], $period))->count()];
        }
        $top = $rows->groupBy('reward_id')->map(fn ($group): array => [
            'reward_id' => $group->first()['reward_id'], 'reward_name' => $group->first()['reward'],
            'voucher_value' => $group->first()['voucherValue'], 'redemptions' => $group->count(),
        ])->sort(fn (array $a, array $b): int => $b['redemptions'] <=> $a['redemptions'] ?: $a['reward_id'] <=> $b['reward_id'])->take(3)->values();

        return ['year' => $year, 'month' => $month,
            'summary' => ['total_redemptions' => $rows->count(), 'total_points_spent' => $rows->sum('pointsUsed'),
                'active_inventory' => (int) Reward::current()->sum('stock_quantity')],
            'monthly_redemptions' => $trend, 'top_rewards' => $top->all()];
    }
}
