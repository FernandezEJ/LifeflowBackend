<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // ========================================
    // LOGIN USER
    // Checks the password securely and issues a token for this login.
    // Failed credentials share one error message to avoid revealing accounts.
    // ========================================
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $user->createToken('mobile')->plainTextToken,
        ]);
    }

    // ========================================
    // CURRENT USER
    // Reads the user identified by Sanctum, never a client-supplied user ID.
    // ========================================
    public function user(Request $request): JsonResponse
    {
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
