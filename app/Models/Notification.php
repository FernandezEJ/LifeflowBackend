<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    // ========================================
    // CONTROLLED IMPORTANT HISTORY
    // Only the trusted service creates these records. Delivery bookkeeping
    // stays private; API readers receive only their own history.
    // ========================================
    public const TYPES = ['admin_announcement', 'donation_completed', 'donation_rejected', 'donation_needs_revision', 'donation_reminder'];

    protected $guarded = ['id'];

    protected $hidden = ['event_key', 'push_attempted_at', 'push_status', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'push_attempted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
