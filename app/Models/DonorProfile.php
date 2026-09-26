<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// EDITABLE PROFILE FIELDS
// Ownership is assigned through the user's relationship, never request input.
// ========================================
#[Fillable(['first_name', 'middle_name', 'last_name', 'mobile_number', 'birth_date', 'gender', 'blood_type'])]
class DonorProfile extends Model
{
    // ========================================
    // DATE REPRESENTATION
    // Keeps birth dates as calendar dates in API responses without a timezone.
    // ========================================
    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d'];
    }

    // ========================================
    // ACCOUNT RELATIONSHIP
    // Links this personal profile to its owning LifeFlow account.
    // ========================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // ACCOUNT DISPLAY NAME
    // Combines the personal names consistently during registration and updates.
    // ========================================
    public static function accountName(array $data): string
    {
        return implode(' ', array_filter([
            $data['first_name'], $data['middle_name'] ?? null, $data['last_name'],
        ], fn ($part) => $part !== null && $part !== ''));
    }
}
