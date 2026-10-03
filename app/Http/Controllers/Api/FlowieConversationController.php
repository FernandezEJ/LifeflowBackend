<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlowieConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlowieConversationController extends Controller
{
    public function index(Request $request, FlowieConversationService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = $request->user()->flowieConversations()->orderByDesc('last_message_at')->orderByDesc('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($item) => $service->metadata($item)));

        return response()->json($page)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $conversation, FlowieConversationService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        // Owner-scoped lookup returns the same 404 for foreign IDs and deleted content.
        $item = $request->user()->flowieConversations()->findOrFail($conversation);

        return response()->json(['conversation' => $service->metadata($item),
            'messages' => $item->messages()->orderBy('created_at')->orderBy('id')->paginate(50)])->header('Cache-Control', 'no-store');
    }

    public function recentlyDeleted(Request $request, FlowieConversationService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = $request->user()->flowieConversations()->onlyTrashed()->orderByDesc('deleted_at')->orderByDesc('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($item) => $service->metadata($item)));

        return response()->json($page)->header('Cache-Control', 'no-store');
    }

    public function end(Request $request, FlowieConversationService $service, ?int $conversation = null): JsonResponse
    {
        $this->emptyBody($request);
        $item = $service->end($request->user(), $conversation);

        return response()->json(['message' => $item ? 'Conversation ended.' : 'No active conversation.',
            'conversation' => $item ? $service->metadata($item) : null])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $conversation, FlowieConversationService $service): JsonResponse
    {
        $this->emptyBody($request);
        $item = $service->delete($request->user(), $conversation);

        return response()->json(['message' => 'Conversation moved to Recently Deleted.',
            'conversation' => $service->metadata($item)])->header('Cache-Control', 'no-store');
    }

    public function restore(Request $request, int $conversation, FlowieConversationService $service): JsonResponse
    {
        $this->emptyBody($request);
        $item = $service->restore($request->user(), $conversation);

        return response()->json(['message' => 'Conversation restored as ended history.',
            'conversation' => $service->metadata($item)])->header('Cache-Control', 'no-store');
    }

    private function emptyBody(Request $request): void
    {
        if ($request->all()) {
            throw ValidationException::withMessages(['request' => 'No request fields are accepted.']);
        }
    }
}
