<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DonationParticipation extends Model
{
    // ========================================
    // SERVER-OWNED ACTIVITY STATE
    // Prevents mass assignment; private storage paths are never returned to donors.
    // ========================================
    protected $guarded = ['*'];

    /** Shared with joining and Flowie; revision requests still occupy the donor's active activity. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'for_verification', 'needs_revision']);
    }

    // ========================================
    // ATOMIC TRUSTED COMPLETION
    // Lock the donor before changing status, then save, award and notify in one
    // transaction. Concurrent completions get distinct donation numbers.
    // Future admin code must use save/update, never bulk SQL or saveQuietly.
    // ========================================
    public function save(array $options = []): bool
    {
        if ($this->status === 'completed' && ($this->isDirty('status') || ! $this->exists)) {
            $this->verified_at ??= now();

            return DB::transaction(function () use ($options) {
                User::whereKey($this->user_id)->lockForUpdate()->firstOrFail();

                return parent::save($options);
            }, 3);
        }

        return parent::save($options);
    }

    protected $hidden = ['proof_storage_path', 'proof_path', 'proof_url'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'cancelled_at' => 'datetime', 'proof_uploaded_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    // ========================================
    // ACCOUNT AND OPPORTUNITY RELATIONSHIPS
    // Keeps joined activity available even after announcement expiry.
    // ========================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(DonationOpportunity::class, 'donation_opportunity_id')->withTrashed()->withDefault(function (DonationOpportunity $opportunity, DonationParticipation $participation): void {
            if ($participation->source_type === 'red_cross_dagupan') {
                $opportunity->forceFill(DonationOpportunity::redCrossDagupan());
            }
        });
    }
}
