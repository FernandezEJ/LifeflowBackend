<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlowieController extends Controller
{
    public function chat(Request $request, GroqService $groq): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['message', 'history'])) {
            throw ValidationException::withMessages(['message' => 'Only message and history are accepted.']);
        }
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['sometimes', 'array', 'list', 'max:20'],
            'history.*' => ['required', 'array:role,text'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:4000'],
        ]);
        $reply = $groq->reply($data['message'], $data['history'] ?? []);

        return $reply === null
            ? response()->json(['message' => 'Flowie is unavailable right now. Please try again shortly.'], 503)
            : response()->json(['reply' => $reply]);
    }
}
