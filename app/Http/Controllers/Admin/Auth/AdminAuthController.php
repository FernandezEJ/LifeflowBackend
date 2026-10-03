<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        return DB::transaction(function () use ($credentials): JsonResponse {
            $user = User::where('email', $credentials['email'])->lockForUpdate()->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password)
                || ! $user->isAdminPanelUser() || $user->isDeactivated()) {
                return response()->json(['message' => 'Invalid email or password.'], 401);
            }

            $user->last_login_at = now()->startOfSecond();
            $user->save();
            $token = $user->createToken('admin-web')->plainTextToken;

            return response()->json(['token' => $token, 'user' => $this->profile($user)])
                ->header('Cache-Control', 'no-store');
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())])
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /** @return array{id: int, name: string, email: string, role: string, must_change_password: bool, last_login_at: ?string} */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
