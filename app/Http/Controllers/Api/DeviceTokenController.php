<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceTokenController extends Controller
{
    // ========================================
    // REGISTER SESSION-BOUND ANDROID FCM ADDRESS
    // A new login on the same device transfers its push address. Rotated tokens
    // replace this session's old token without changing other devices.
    // ========================================
    public function store(Request $request): JsonResponse
    {
        $this->only($request, ['token', 'platform', 'provider']);
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096', 'regex:~^[A-Za-z0-9_:\-]+$~D'],
            'platform' => ['required', 'in:android'], 'provider' => ['required', 'in:fcm'],
        ]);
        $session = $request->user()->currentAccessToken();
        abort_unless($session instanceof PersonalAccessToken, 422, 'A mobile bearer session is required.');
        DB::transaction(function () use ($request, $data, $session) {
            PersonalAccessToken::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $data['token']);
            DeviceToken::where('personal_access_token_id', $session->id)->where('token_hash', '!=', $hash)->delete();
            DeviceToken::upsert([[
                ...$data, 'token_hash' => $hash, 'user_id' => $request->user()->id,
                'personal_access_token_id' => $session->id, 'last_seen_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['token_hash'], ['token', 'user_id', 'personal_access_token_id', 'platform', 'provider', 'last_seen_at', 'updated_at']);
        });

        return response()->json(['registered' => true]);
    }

    // ========================================
    // OWNED IDEMPOTENT UNREGISTER
    // Does not reveal or delete another user's push address.
    // ========================================
    public function destroy(Request $request): JsonResponse
    {
        $this->only($request, ['token']);
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $request->user()->deviceTokens()->where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['removed' => true]);
    }

    // ========================================
    // STRICT INPUT OWNERSHIP
    // All identity fields belong to Sanctum, never to the submitted body.
    // ========================================
    private function only(Request $request, array $allowed): void
    {
        $extra = array_diff(array_keys($request->all()), $allowed);
        if ($extra) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'This field is not allowed.'));
        }
    }
}
