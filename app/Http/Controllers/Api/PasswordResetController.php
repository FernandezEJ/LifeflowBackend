<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordResetRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function requestCode(PasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->requestCode($request->validated('email'));

        return response()->json(['message' => PasswordResetService::NOTICE, 'resend_after' => PasswordResetService::RESEND_SECONDS], 202);
    }

    public function verify(PasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $token = $service->verify($request->validated('email'), $request->validated('code'));

        return response()->json(['reset_token' => $token, 'expires_in' => PasswordResetService::TOKEN_MINUTES * 60])
            ->header('Cache-Control', 'no-store');
    }

    public function reset(PasswordResetRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->reset($request->validated('email'), $request->validated('reset_token'), $request->validated('password'));

        return response()->json(['message' => 'Your password has been updated successfully.']);
    }
}
