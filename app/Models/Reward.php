<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

// ========================================
// FUTURE ADMIN INVENTORY MODEL
// Ordinary saves enforce finite stock and a positive cost.
// No donor endpoint can write these fields.
// ========================================
class Reward extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'points_cost' => 'integer', 'stock_quantity' => 'integer',
            'voucher_value' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Reward $reward) {
            Validator::make($reward->getAttributes(), [
                'name' => ['required', 'string', 'max:255'],
                'points_cost' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
                'voucher_value' => ['nullable', 'numeric', 'min:0'],
                'image_url' => ['nullable', 'url:https', 'max:2048'],
                'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', ...($reward->starts_at ? ['after:starts_at'] : [])],
            ])->validate();
        });
    }

    // ========================================
    // DONOR VISIBILITY
    // Sold-out rewards remain visible while inactive/scheduled items stay hidden.
    // ========================================
    public function scopeCurrent(Builder $query): void
    {
        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
