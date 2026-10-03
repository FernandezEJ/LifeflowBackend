<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlowieConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlowieController extends Controller
{
    public function chat(Request $request, FlowieConversationService $conversations): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['message', 'history', 'conversation_id'])) {
            throw ValidationException::withMessages(['message' => 'Only message, history and conversation_id are accepted.']);
        }
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['sometimes', 'integer', 'min:1'],
            'history' => ['sometimes', 'array', 'list', 'max:20'],
            'history.*' => ['required', 'array:role,text'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:4000'],
        ]);
        $result = $conversations->chat($request->user(), $data['message'], $data['history'] ?? [], $data['conversation_id'] ?? null);

        return $result['reply'] === null
            ? response()->json(['message' => 'Flowie is unavailable right now. Please try again shortly.'], 503)
            : response()->json($result)->header('Cache-Control', 'no-store');
    }
}
