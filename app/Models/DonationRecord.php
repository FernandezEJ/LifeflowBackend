<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// HISTORICAL AND TRUSTED OUTCOME DATA
// Donor writes are disabled. Future trusted verification creates completed records.
// Ownership, participation links, and verification state cannot be mass-assigned.
// ========================================
#[Fillable(['donation_date', 'location', 'notes'])]
class DonationRecord extends Model
{
    // ========================================
    // DATE REPRESENTATION
    // Calendar donation dates stay timezone-free; event timestamps serialize as ISO dates.
    // ========================================
    protected function casts(): array
    {
        return ['donation_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    // ========================================
    // DONOR ACCOUNT
    // Links this donation record to its submitting account.
    // ========================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // VERIFIED PARTICIPATION LINK
    // Nullable for legacy rows; the unique database link prevents double counting.
    // ========================================
    public function participation(): BelongsTo
    {
        return $this->belongsTo(DonationParticipation::class, 'donation_participation_id');
    }
}
