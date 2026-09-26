<?php

namespace App\Services;

use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PointsService
{
    // ========================================
    // LEDGER BALANCE
    // Locking reads see the latest committed balance even inside a MySQL transaction.
    // The caller locks the donor row before awards or spending.
    // ========================================
    public function summary(int $userId): array
    {
        $amounts = PointTransaction::where('user_id', $userId)->pluck('amount');

        return ['current_balance' => (int) $amounts->sum(),
            'total_earned' => (int) $amounts->filter(fn ($n) => $n > 0)->sum(),
            'total_spent' => (int) -$amounts->filter(fn ($n) => $n < 0)->sum()];
    }

    public function balance(int $userId): int
    {
        return (int) PointTransaction::where('user_id', $userId)->lockForUpdate()->get(['amount'])->sum('amount');
    }

    // ========================================
    // COMPLETED HISTORY AND AUTOMATIC AWARD
    // The model locks the donor before saving a completion. Count completed
    // participations plus completed legacy records not already represented by them.
    // Historical rows affect numbering; this does not retroactively award them.
    // ========================================
    public function awardDonation(DonationParticipation $participation): ?PointTransaction
    {
        if ($participation->status !== 'completed') {
            return null;
        }

        return DB::transaction(function () use ($participation) {
            User::whereKey($participation->user_id)->lockForUpdate()->firstOrFail();
            $current = DonationParticipation::whereKey($participation->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'completed') {
                return null;
            }
            $key = 'donation:'.$current->id.':reward';
            $existing = PointTransaction::where('user_id', $current->user_id)->where('event_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $ids = DonationParticipation::where('user_id', $current->user_id)->where('status', 'completed')->lockForUpdate()->get(['id'])->pluck('id');
            $legacy = DonationRecord::where('user_id', $current->user_id)->where('status', 'completed')
                ->where(fn ($q) => $q->whereNull('donation_participation_id')->orWhereNotIn('donation_participation_id', $ids))
                ->lockForUpdate()->get(['id'])->count();
            $number = $ids->count() + $legacy;
            $amount = min(500, 300 + 50 * max(0, $number - 1));

            return $this->record($current->user_id, 'donation_reward', $amount, 'donation_participation', $current->id,
                'Completed donation #'.$number, $key);
        }, 3);
    }

    // ========================================
    // VOUCHER DEBIT
    // Called inside the redemption transaction after locking the donor.
    // One voucher always has at most one debit.
    // ========================================
    public function deduct(UserVoucher $voucher): PointTransaction
    {
        $key = 'reward_redemption:'.$voucher->id;
        $existing = PointTransaction::where('user_id', $voucher->user_id)->where('event_key', $key)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }
        if ($voucher->points_spent <= 0 || $this->balance($voucher->user_id) < $voucher->points_spent) {
            throw ValidationException::withMessages(['reward' => 'Not enough points for this reward.']);
        }

        return $this->record($voucher->user_id, 'reward_redemption', -$voucher->points_spent,
            'user_voucher', $voucher->id, 'Reward redeemed', $key);
    }

    private function record(int $userId, string $type, int $amount, string $source, int $sourceId, string $description, string $eventKey): PointTransaction
    {
        $row = new PointTransaction;
        $row->forceFill(['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'source_type' => $source,
            'source_id' => $sourceId, 'description' => $description, 'event_key' => $eventKey])->save();

        return $row;
    }
}
