<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// PRIVATE SINGLE-USE VOUCHER
// Token is hidden by default; the service exposes it only during an active window.
// ========================================
class UserVoucher extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['user_id', 'voucher_token', 'redemption_key'];

    protected function casts(): array
    {
        return ['reward_snapshot' => 'array', 'points_spent' => 'integer', 'activated_at' => 'datetime', 'expires_at' => 'datetime', 'redeemed_at' => 'datetime'];
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
