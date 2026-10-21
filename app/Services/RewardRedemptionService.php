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
    public function __construct(private readonly MediaStorage $media) {}

    // ========================================
    // ATOMIC REDEMPTION
    // Always lock donor then inventory. Different rewards cannot overspend the
    // same balance; different donors cannot buy the same final stock unit.
    // A persisted request UUID lets a lost response be retried safely.
    // ========================================
    public function redeem(int $userId, int $rewardId, string $requestKey, ?int $variantId = null): UserVoucher
    {
        return DB::transaction(function () use ($userId, $rewardId, $requestKey, $variantId) {
            $owner = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_unless($owner->isDonor() && ! $owner->isDeactivated(), 403, 'Only active donors may redeem rewards.');
            $existing = UserVoucher::where('user_id', $userId)->where('redemption_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->reward_id !== $rewardId || $existing->reward_variant_id !== $variantId) {
                    throw ValidationException::withMessages(['request_key' => 'This request key belongs to another reward or size.']);
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
            $variant = null;
            if ($reward->category === 'apparel') {
                $variant = $reward->variants()->whereKey($variantId)->lockForUpdate()->first();
                if (! $variant || ! $variant->enabled || $variant->stock_quantity < 1) {
                    throw ValidationException::withMessages(['variant_id' => 'Choose an offered, in-stock size. Refresh inventory and try again.']);
                }
            } elseif ($variantId !== null) {
                throw ValidationException::withMessages(['variant_id' => 'This reward does not offer sizes.']);
            }
            $voucher = new UserVoucher;
            $voucher->forceFill(['user_id' => $userId, 'reward_id' => $rewardId, 'points_spent' => $reward->points_cost,
                'reward_variant_id' => $variant?->id, 'status' => 'available', 'voucher_token' => Str::random(64), 'redemption_key' => $requestKey,
                'reward_snapshot' => ['id' => $reward->id, 'name' => $reward->name, 'description' => $reward->description, 'category' => $reward->category,
                    'size' => $variant?->size, 'variant_id' => $variant?->id,
                    'voucher_value' => $reward->voucher_value, 'points_cost' => $reward->points_cost,
                    'image_url' => $this->media->snapshotReference($reward)]])->save();
            app(PointsService::class)->deduct($voucher);
            if ($variant) {
                $variant->decrement('stock_quantity');
            }
            $reward->stock_quantity--;
            $reward->inventory_version++;
            $reward->save();

            return $voucher;
        }, 3);
    }
}
