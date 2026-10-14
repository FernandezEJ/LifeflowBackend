<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// PRIVATE SINGLE-USE VOUCHER
// Legacy QR tokens and claim secrets are never part of model serialization.
// ========================================
class UserVoucher extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['user_id', 'voucher_token', 'redemption_key', 'claim_code_encrypted', 'claim_code_digest', 'claimed_by_user_id'];

    protected function casts(): array
    {
        return ['reward_snapshot' => 'array', 'points_spent' => 'integer', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'redeemed_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function claimStatus(): string
    {
        if ($this->claimed_at !== null) {
            return 'claimed';
        }
        if ($this->status === 'active' && $this->claim_code_digest !== null) {
            return 'pending_claim';
        }

        return $this->status === 'available' && $this->claim_code_digest === null ? 'available' : 'legacy';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }
}
