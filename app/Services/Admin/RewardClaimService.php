<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\UserVoucher;
use App\Services\MediaStorage;
use App\Services\RewardClaimCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RewardClaimService
{
    public function __construct(private readonly RewardClaimCode $codes, private readonly MediaStorage $media) {}

    public function verify(#[\SensitiveParameter] string $code): array
    {
        try {
            $voucher = $this->lookup($code)->first();
        } catch (QueryException) {
            abort(503, 'Claim verification is unavailable. Please try again shortly.');
        }
        abort_unless($voucher, 404, 'Claim code not found.');

        return $this->details($voucher);
    }

    public function confirm(User $actor, #[\SensitiveParameter] string $code): array
    {
        try {
            return DB::transaction(function () use ($actor, $code): array {
                $staff = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($staff->isAdminPanelUser() && ! $staff->isDeactivated() && ! $staff->must_change_password, 403, 'Access denied.');
                $voucher = $this->lookup($code)->lockForUpdate()->first();
                abort_unless($voucher, 404, 'Claim code not found.');
                abort_if($voucher->claimed_at !== null, 409, 'This reward has already been claimed.');
                abort_unless($voucher->claimStatus() === 'pending_claim', 409, 'This claim is unavailable.');
                $time = now()->startOfSecond();
                $voucher->forceFill(['status' => 'redeemed', 'claimed_at' => $time, 'claimed_by_user_id' => $staff->id,
                    'redeemed_at' => $time])->save();
                $staff->auditLogs()->create(['actor_role' => $staff->role, 'action' => 'reward_claimed', 'module' => 'rewards',
                    'target_type' => 'user_voucher', 'target_id' => $voucher->id,
                    'details' => ['reward_id' => $voucher->reward_id]]);

                return $this->details($voucher);
            }, 3);
        } catch (QueryException) {
            // Never surface or log a database exception whose bindings include the lookup digest.
            abort(503, 'Claim confirmation is unavailable. Please try again shortly.');
        }
    }

    private function lookup(#[\SensitiveParameter] string $code): Builder
    {
        return UserVoucher::whereIn('claim_code_digest', $this->codes->digests($code));
    }

    private function details(UserVoucher $voucher): array
    {
        $status = $voucher->claimStatus();
        $reward = Arr::only($voucher->reward_snapshot ?? [], ['id', 'name', 'description', 'category', 'voucher_value', 'points_cost', 'image_url', 'size', 'variant_id']);
        $reward['image_url'] = $this->media->snapshotUrl($reward['image_url'] ?? null);

        return ['voucher_id' => $voucher->id, 'donor_name' => $voucher->user?->name ?? 'Unavailable donor',
            'reward' => $reward,
            'points_spent' => $voucher->points_spent, 'claim_status' => $status,
            'status_label' => match ($status) {
                'claimed' => 'Already Claimed', 'pending_claim' => 'Pending Claim', default => 'Unavailable',
            },
            'can_claim' => $status === 'pending_claim', 'claimed_at' => $voucher->claimed_at?->toISOString()];
    }
}
