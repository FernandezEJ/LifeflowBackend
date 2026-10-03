<?php

namespace App\Models;

use Database\Factories\FlowieConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowieConversation extends Model
{
    /** @use HasFactory<FlowieConversationFactory> */
    use HasFactory, SoftDeletes;

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const RECOVERY_DAYS = 30;

    protected $fillable = ['title', 'status', 'ended_at', 'last_message_at'];

    protected $hidden = ['user_id', 'active_slot'];

    protected function casts(): array
    {
        return ['ended_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FlowieMessage::class);
    }
}
