<?php

namespace App\Models;

use Database\Factories\FlowieMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowieMessage extends Model
{
    /** @use HasFactory<FlowieMessageFactory> */
    use HasFactory;

    protected $fillable = ['role', 'content'];

    protected $hidden = ['flowie_conversation_id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FlowieConversation::class, 'flowie_conversation_id');
    }
}
