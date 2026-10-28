<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    // ========================================
    // CURRENT USER
    // Reads the user identified by Sanctum, never a client-supplied user ID.
    // ========================================
    public function user(Request $request): JsonResponse
    {
        // Mobile restoration clears a rejected session on 401. Staff restore through /admin/me.
        abort_unless($request->user()?->isActiveVerifiedDonor(), 401, 'Please log in again.');

        return response()->json($request->user());
    }

    // ========================================
    // LOGOUT USER
    // Revokes only the token used for this request, preserving other devices.
    // ========================================
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful']);
    }
}
