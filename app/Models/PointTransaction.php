<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// SERVER-OWNED POINT HISTORY
// Services write the ledger; donor input cannot choose an amount or owner.
// ========================================
class PointTransaction extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['user_id', 'event_key'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
