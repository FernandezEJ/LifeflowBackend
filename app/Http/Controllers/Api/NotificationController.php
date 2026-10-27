<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DonationOpportunity;
use App\Services\DonationReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Retained publication details are readable only through the recipient's own notice. */
    public function announcement(Request $request, int $id): JsonResponse
    {
        $notice = $request->user()->notifications()->where('type', 'admin_announcement')->findOrFail($id);
        $opportunityId = filter_var($notice->data['opportunity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($opportunityId === false, 404);
        $now = now();
        $item = DonationOpportunity::query()->where('source_type', 'admin_announcement')
            ->where('status', 'published')->where('published_at', '<=', $now)->whereNotNull('expires_at')
            ->findOrFail($opportunityId);
        $expired = $item->expires_at->lte($now);

        return response()->json(['opportunity' => $item, 'view_state' => $expired ? 'expired' : 'active',
            'read_only' => $expired, 'server_time' => $now->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function reminders(Request $request, DonationReminderService $reminders): JsonResponse
    {
        return response()->json($reminders->forHome($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function preparation(Request $request, int $id, DonationReminderService $reminders): JsonResponse
    {
        return response()->json(['reminder' => $reminders->preparation($request->user(), $id)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function acknowledgePreparation(Request $request, int $id, DonationReminderService $reminders): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'No additional fields are accepted.');
        $reminders->preparation($request->user(), $id, true);

        return response()->json(['acknowledged' => true]);
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
