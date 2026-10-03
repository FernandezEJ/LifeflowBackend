<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_user_id', 'actor_role', 'action', 'module', 'target_type', 'target_id', 'details'])]
class AuditLog extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    protected function casts(): array
    {
        return ['actor_role' => UserRole::class, 'details' => 'array'];
    }
}
