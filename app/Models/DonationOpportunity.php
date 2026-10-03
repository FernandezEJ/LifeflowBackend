<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DonationOpportunity extends Model
{
    use SoftDeletes;

    protected $hidden = ['image_path', 'created_by'];

    protected $appends = ['image_url', 'donation_date'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function getDonationDateAttribute(): ?string
    {
        return $this->event_date?->toDateString();
    }

    public function managementStatus(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }
        if ($this->status === 'draft') {
            return 'draft';
        }

        return $this->status === 'published' && $this->published_at?->lte(now()) && $this->expires_at?->gt(now()) ? 'active' : 'expired';
    }

    // ========================================
    // SERVER-MANAGED ANNOUNCEMENT
    // No donor write endpoint exists for this model.
    // ========================================
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'published_at' => 'datetime', 'expires_at' => 'datetime', 'points_reward' => 'integer'];
    }

    // ========================================
    // ACTIVE PUBLICATION WINDOW
    // Expired and unpublished posts remain stored but are excluded from the board.
    // ========================================
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('donation_opportunities.deleted_at')->where('status', 'published')->where('published_at', '<=', now())->where('expires_at', '>', now());
    }

    public function participations(): HasMany
    {
        return $this->hasMany(DonationParticipation::class);
    }

    // Permanent system option metadata, not an admin announcement or a scheduled event.
    public static function redCrossDagupan(): array
    {
        return [
            'id' => null, 'source_type' => 'red_cross_dagupan',
            'title' => 'Philippine Red Cross ? Dagupan City Chapter',
            'description' => 'Choose this option when you plan to donate through the Philippine Red Cross Dagupan City Chapter. Confirm donation arrangements with the chapter before visiting. Final donation eligibility is determined by the facility.',
            'location' => 'Dagupan City Chapter', 'event_date' => null,
            'start_time' => null, 'end_time' => null, 'points_reward' => 0,
            'published_at' => null, 'expires_at' => null, 'status' => 'available',
        ];
    }
}
