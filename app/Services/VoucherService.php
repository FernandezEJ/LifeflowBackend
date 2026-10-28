<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    public function __construct(private readonly RewardClaimCode $codes, private readonly MediaStorage $media) {}

    /** Issue once under owner/voucher locks. No spending, inventory changes or timer reconciliation. */
    public function activate(int $userId, int $id): UserVoucher
    {
        try {
            return DB::transaction(function () use ($userId, $id): UserVoucher {
                $owner = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                abort_unless($owner->isActiveVerifiedDonor(), 403, 'Access denied.');
                $voucher = UserVoucher::where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($voucher->claimed_at !== null || $voucher->status === 'redeemed') {
                    return $voucher;
                }
                if (! in_array($voucher->status, ['available', 'active'], true)) {
                    throw ValidationException::withMessages(['voucher' => 'This voucher cannot be activated.']);
                }
                if ($voucher->claim_code_digest !== null) {
                    return $voucher;
                }
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    $code = $this->codes->generate();
                    $digests = $this->codes->digests($code);
                    if (UserVoucher::whereIn('claim_code_digest', $digests)->exists()) {
                        continue;
                    }
                    try {
                        // A savepoint keeps a unique-index collision from aborting the outer transaction.
                        DB::transaction(function () use ($voucher, $code, $digests): void {
                            $voucher->forceFill(['status' => 'active', 'activated_at' => now()->startOfSecond(),
                                'expires_at' => null, 'claim_code_encrypted' => Crypt::encryptString($code),
                                'claim_code_digest' => $digests[0]])->save();
                        });

                        return $voucher;
                    } catch (UniqueConstraintViolationException) {
                        // Never log the failed query/bindings or include the digest/code in an exception.
                        $voucher->refresh();
                    }
                }
                abort(503, 'A claim code could not be issued. Please try again shortly.');
            }, 3);
        } catch (QueryException) {
            // SQL bindings may contain claim secrets; do not report or chain the database exception.
            abort(503, 'Claim activation is unavailable. Please try again shortly.');
        }
    }

    // ========================================
    // OWNER-ONLY PRESENTATION
    // Claimed and legacy vouchers never reveal a usable credential.
    // ========================================
    public function payload(UserVoucher $voucher, int $ownerId): array
    {
        abort_unless($voucher->user_id === $ownerId, 404);
        $voucher->loadMissing('reward');
        $data = $voucher->toArray();
        $data['reward'] = [...($data['reward'] ?? []), ...($voucher->reward_snapshot ?? [])];
        $data['reward']['image_url'] = $this->media->snapshotUrl($data['reward']['image_url'] ?? null);
        unset($data['reward_snapshot']);
        $data['claim_status'] = $voucher->claimStatus();
        $data['is_legacy'] = $data['claim_status'] === 'legacy';
        $data['legacy_status'] = $data['is_legacy'] ? $voucher->status : null;
        $data['claim_code'] = $data['claim_status'] === 'pending_claim' ? Crypt::decryptString($voucher->claim_code_encrypted) : null;

        return $data;
    }
}
