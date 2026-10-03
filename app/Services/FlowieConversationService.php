<?php

namespace App\Services;

use App\Models\FlowieConversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowieConversationService
{
    public function __construct(private readonly GroqService $groq, private readonly FlowieDonorContextService $donorContext) {}

    /** Recheck the owner and bearer session under the same user-first lock as lifecycle writes. */
    private function owner(User $actor): User
    {
        $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->isDonor() && ! $user->isDeactivated(), 403, 'Only active donors may use Flowie.');
        $tokenId = $actor->currentAccessToken()?->getKey();
        $token = $tokenId ? $user->tokens()->find($tokenId) : null;
        abort_unless($token && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Please log in again.');

        return $user;
    }

    /**
     * Preserve the existing provider contract; client history is context, never imported as stored messages.
     * User messages commit before Groq, so failures retain the request without a fabricated assistant reply.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     * @return array{reply: ?string, conversation_id: int}
     */
    public function chat(User $actor, string $message, array $history = [], ?int $conversationId = null): array
    {
        // Serialize donor sends without holding a database transaction across the provider's network call.
        $lock = Cache::lock('flowie-chat:'.$actor->id, 90);
        abort_unless($lock->get(), 409, 'Flowie is already replying. Please wait before sending another message.');
        try {
            $snapshot = DB::transaction(function () use ($actor, $message, $conversationId): array {
                $user = $this->owner($actor);
                $query = $user->flowieConversations()->lockForUpdate();
                $conversation = $conversationId !== null
                    ? $query->findOrFail($conversationId)
                    : $query->where('status', FlowieConversation::ACTIVE)->first();
                if ($conversation) {
                    abort_unless($conversation->status === FlowieConversation::ACTIVE, 409, 'This conversation has ended. Start a new chat.');
                } else {
                    // User-first locking plus the generated unique key prevent two active conversations.
                    $conversation = $user->flowieConversations()->create([
                        'title' => Str::limit(Str::squish($message), 80) ?: 'Flowie Conversation',
                        'status' => FlowieConversation::ACTIVE,
                    ]);
                }
                $conversation->messages()->create(['role' => 'user', 'content' => $message]);
                $conversation->update(['last_message_at' => now()]);

                return ['id' => $conversation->id, 'context' => $this->donorContext->forDonor($user)];
            }, 3);

            $id = $snapshot['id'];
            // Context is only a system-prompt input; E1 persists only the donor text and actual reply.
            $reply = $this->groq->reply($message, $history, $snapshot['context']);
            if ($reply !== null) {
                DB::transaction(function () use ($actor, $id, $reply): void {
                    $conversation = $this->owner($actor)->flowieConversations()->lockForUpdate()->findOrFail($id);
                    // End/delete/restore during Groq must not append to read-only or deleted history.
                    abort_unless($conversation->status === FlowieConversation::ACTIVE, 409, 'This conversation has ended. Start a new chat.');
                    $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);
                    $conversation->update(['last_message_at' => now()]);
                }, 3);
            }

            return ['reply' => $reply, 'conversation_id' => $id];
        } finally {
            $lock->release();
        }
    }

    public function end(User $actor, ?int $id = null): ?FlowieConversation
    {
        return DB::transaction(function () use ($actor, $id): ?FlowieConversation {
            $query = $this->owner($actor)->flowieConversations()->lockForUpdate();
            $conversation = $id !== null ? $query->findOrFail($id) : $query->where('status', FlowieConversation::ACTIVE)->first();
            if ($conversation && $conversation->status === FlowieConversation::ACTIVE) {
                $conversation->update(['status' => FlowieConversation::ENDED, 'ended_at' => now()]);
            }

            return $conversation;
        }, 3);
    }

    public function delete(User $actor, int $id): FlowieConversation
    {
        return DB::transaction(function () use ($actor, $id): FlowieConversation {
            $conversation = $this->owner($actor)->flowieConversations()->withTrashed()->lockForUpdate()->findOrFail($id);
            if (! $conversation->trashed()) {
                $conversation->delete();
            }

            return $conversation;
        }, 3);
    }

    public function restore(User $actor, int $id): FlowieConversation
    {
        return DB::transaction(function () use ($actor, $id): FlowieConversation {
            $conversation = $this->owner($actor)->flowieConversations()->onlyTrashed()->lockForUpdate()->findOrFail($id);
            abort_if($conversation->deleted_at->lte(now()->subDays(FlowieConversation::RECOVERY_DAYS)), 410, 'The 30-day recovery period has ended.');
            // Recovery always returns history as ended, even when there is no other active chat.
            $conversation->fill(['status' => FlowieConversation::ENDED, 'ended_at' => $conversation->ended_at ?? now()]);
            $conversation->restore();

            return $conversation;
        }, 3);
    }

    /** Purge only expired soft deletions; ending a conversation alone never starts retention. */
    public function purge(): int
    {
        $count = 0;
        $cutoff = now()->subDays(FlowieConversation::RECOVERY_DAYS);
        FlowieConversation::onlyTrashed()->where('deleted_at', '<=', $cutoff)->chunkById(100, function ($items) use ($cutoff, &$count): void {
            foreach ($items as $item) {
                $count += DB::transaction(function () use ($item, $cutoff): int {
                    User::withTrashed()->whereKey($item->user_id)->lockForUpdate()->first();
                    $expired = FlowieConversation::onlyTrashed()->where('deleted_at', '<=', $cutoff)->lockForUpdate()->find($item->id);
                    if (! $expired) {
                        return 0;
                    }
                    $expired->forceDelete();

                    return 1;
                }, 3);
            }
        });

        return $count;
    }

    /** Explicit metadata keeps owner IDs and the generated constraint field private. */
    public function metadata(FlowieConversation $conversation): array
    {
        $data = ['id' => $conversation->id, 'title' => $conversation->title ?: 'Flowie Conversation',
            'status' => $conversation->status, 'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(), 'ended_at' => $conversation->ended_at?->toISOString(),
            'last_message_at' => $conversation->last_message_at?->toISOString()];
        if ($conversation->trashed()) {
            $deadline = $conversation->deleted_at->copy()->addDays(FlowieConversation::RECOVERY_DAYS);
            $data += ['deleted_at' => $conversation->deleted_at->toISOString(), 'permanent_delete_at' => $deadline->toISOString(),
                'days_remaining' => max(0, (int) ceil(now()->diffInSeconds($deadline, false) / 86400)),
                'recoverable' => $deadline->isFuture()];
        }

        return $data;
    }
}
