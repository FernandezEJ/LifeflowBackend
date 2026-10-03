<?php

namespace App\Services;

use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardRedemptionService
{
    // ========================================
    // ATOMIC REDEMPTION
    // Always lock donor then inventory. Different rewards cannot overspend the
    // same balance; different donors cannot buy the same final stock unit.
    // A persisted request UUID lets a lost response be retried safely.
    // ========================================
    public function redeem(int $userId, int $rewardId, string $requestKey): UserVoucher
    {
        return DB::transaction(function () use ($userId, $rewardId, $requestKey) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = UserVoucher::where('user_id', $userId)->where('redemption_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->reward_id !== $rewardId) {
                    throw ValidationException::withMessages(['request_key' => 'This request key belongs to another reward.']);
                }

                return $existing;
            }
            $reward = Reward::whereKey($rewardId)->lockForUpdate()->firstOrFail();
            if (! $reward->active || ($reward->starts_at && $reward->starts_at->isFuture()) || ($reward->ends_at && $reward->ends_at->lte(now()))) {
                throw ValidationException::withMessages(['reward' => 'This reward is not available.']);
            }
            if ($reward->stock_quantity <= 0 || $reward->points_cost <= 0) {
                throw ValidationException::withMessages(['reward' => 'This reward is out of stock.']);
            }
            $voucher = new UserVoucher;
            $voucher->forceFill(['user_id' => $userId, 'reward_id' => $rewardId, 'points_spent' => $reward->points_cost,
                'status' => 'available', 'voucher_token' => Str::random(64), 'redemption_key' => $requestKey,
                'reward_snapshot' => ['id' => $reward->id, 'name' => $reward->name, 'description' => $reward->description,
                    'voucher_value' => $reward->voucher_value, 'points_cost' => $reward->points_cost, 'image_url' => $reward->image_url]])->save();
            app(PointsService::class)->deduct($voucher);
            $reward->stock_quantity--;
            $reward->save();

            return $voucher;
        }, 3);
    }
}
