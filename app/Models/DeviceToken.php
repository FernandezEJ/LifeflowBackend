<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceToken extends Model
{
    // ========================================
    // PRIVATE PUSH ADDRESS AND LOGIN SESSION
    // Hash uniqueness avoids MySQL token-length/collation collisions.
    // Deleting the associated Sanctum login also removes this registration.
    // ========================================
    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash', 'personal_access_token_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
