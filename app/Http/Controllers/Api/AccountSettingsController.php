<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountSettingsRequest;
use App\Services\AccountSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountSettingsController extends Controller
{
    private function respond(\Closure $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status)->header('Cache-Control', 'no-store');
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            Log::error('Account settings request failed. Check server configuration.');

            return response()->json(['message' => 'The change could not be confirmed. Please refresh your profile before retrying.'], 500)->header('Cache-Control', 'no-store');
        }
    }

    public function requestCode(AccountSettingsRequest $request, AccountSettingsService $service): JsonResponse
    {
        return $this->respond(fn () => $service->request($request->user(), $request->validated('current_password'), $request->validated('new_email')), 202);
    }

    public function resend(AccountSettingsRequest $request, AccountSettingsService $service): JsonResponse
    {
        return $this->respond(fn () => $service->resend($request->user(), $request->validated('pending_token')), 202);
    }

    public function verify(AccountSettingsRequest $request, AccountSettingsService $service): JsonResponse
    {
        return $this->respond(fn () => ['message' => 'Email changed successfully.', 'user' => $service->verify($request->user(), $request->validated('pending_token'), $request->validated('code'))]);
    }

    public function password(AccountSettingsRequest $request, AccountSettingsService $service): JsonResponse
    {
        return $this->respond(function () use ($request, $service): array {
            $service->password($request->user(), $request->validated('current_password'), $request->validated('password'));

            return ['message' => 'Password updated successfully.'];
        });
    }
}
