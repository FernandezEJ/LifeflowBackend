<?php

namespace App\Services;

use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    // ========================================
    // SERVER DEADLINE RECONCILIATION
    // Five elapsed minutes mean redeemed in this capstone, never proof of a scan.
    // Conditional updates are safe across simultaneous list/detail/activate calls.
    // ========================================
    public function reconcile(int $userId, ?int $id = null): void
    {
        UserVoucher::where('user_id', $userId)->when($id !== null, fn ($q) => $q->whereKey($id))->where('status', 'active')->where('expires_at', '<=', now())
            ->update(['status' => 'redeemed', 'redeemed_at' => DB::raw('expires_at'), 'updated_at' => now()]);
    }

    public function activate(int $userId, int $id): UserVoucher
    {
        return DB::transaction(function () use ($userId, $id) {
            $voucher = UserVoucher::where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->reconcile($userId, $id);
            $voucher->refresh();
            if ($voucher->status === 'active' || $voucher->status === 'redeemed') {
                return $voucher;
            }
            if ($voucher->status !== 'available') {
                throw ValidationException::withMessages(['voucher' => 'This voucher cannot be activated.']);
            }
            $start = now()->startOfSecond();
            $voucher->forceFill(['status' => 'active', 'activated_at' => $start, 'expires_at' => $start->copy()->addMinutes(5)])->save();

            return $voucher;
        }, 3);
    }

    // ========================================
    // SAFE MOBILE PRESENTATION
    // Opaque token is returned only while active; ownership is checked by callers.
    // ========================================
    public function payload(UserVoucher $voucher): array
    {
        $voucher->loadMissing('reward');
        $data = $voucher->toArray();
        $data['qr_token'] = $voucher->status === 'active' && $voucher->expires_at?->isFuture() ? $voucher->voucher_token : null;

        return $data;
    }
}
