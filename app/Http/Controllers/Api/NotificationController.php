<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DonationReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function reminders(Request $request, DonationReminderService $reminders): JsonResponse
    {
        return response()->json($reminders->forHome($request->user()));
    }

    // ========================================
    // PRIVATE PAGINATED INBOX
    // Ownership always comes from Sanctum; unread filtering spans every page.
    // ========================================
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['unread' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = $request->user()->notifications()->orderByDesc('id');
        if ($input['unread'] ?? false) {
            $query->whereNull('read_at');
        }

        return response()->json($query->paginate(20));
    }

    // ========================================
    // READ STATE AND BADGE
    // Preserve the original read timestamp and mutate only the current inbox.
    // ========================================
    public function read(Request $request, int $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $request->user()->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['notification' => $notification->fresh()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $request->user()->notifications()->whereNull('read_at')->count()]);
    }
}
