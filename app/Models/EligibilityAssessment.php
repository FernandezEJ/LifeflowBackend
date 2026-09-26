<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ========================================
// SERVER-CREATED ASSESSMENT DATA
// Controllers supply evaluated data; ownership comes only from the relationship.
// ========================================
#[Fillable(['result', 'reasons', 'answers', 'assessed_at'])]
class EligibilityAssessment extends Model
{
    // ========================================
    // SNAPSHOT AND DATE CASTS
    // Returns structured answers/reasons and an ISO assessment timestamp.
    // ========================================
    protected function casts(): array
    {
        return ['answers' => 'array', 'reasons' => 'array', 'assessed_at' => 'datetime'];
    }

    // ========================================
    // DONOR ACCOUNT
    // Each saved assessment belongs to the account that submitted it.
    // ========================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
