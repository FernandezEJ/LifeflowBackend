<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPasswordResetRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class AdminPasswordResetController extends Controller
{
    public function requestCode(AdminPasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->requestCode($request->validated('email'), true);

        return response()->json(['message' => PasswordResetService::NOTICE, 'resend_after' => PasswordResetService::RESEND_SECONDS], 202);
    }

    public function verify(AdminPasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $token = $service->verify($request->validated('email'), $request->validated('code'), true);

        return response()->json(['reset_token' => $token, 'expires_in' => PasswordResetService::TOKEN_MINUTES * 60])->header('Cache-Control', 'no-store');
    }

    public function reset(AdminPasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->reset($request->validated('email'), $request->validated('reset_token'), $request->validated('password'), true);

        return response()->json(['message' => 'Your password has been updated successfully.']);
    }
}
