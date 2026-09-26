<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DonorProfileRequest;
use App\Http\Requests\RegistrationVerificationRequest;
use App\Services\RegistrationVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RegistrationVerificationController extends Controller
{
    public function requestCode(DonorProfileRequest $request, RegistrationVerificationService $service): JsonResponse
    {
        return response()->json($service->request($request->validated()), 202)->header('Cache-Control', 'no-store');
    }

    public function resend(RegistrationVerificationRequest $request, RegistrationVerificationService $service): JsonResponse
    {
        return response()->json($service->resend($request->validated('pending_token')), 202);
    }

    public function verify(RegistrationVerificationRequest $request, RegistrationVerificationService $service): JsonResponse
    {
        try {
            return response()->json($service->verify($request->validated('pending_token'), $request->validated('code')), 201)
                ->header('Cache-Control', 'no-store');
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            Log::error('Registration could not be completed. Account creation was rolled back.');

            return response()->json(['message' => 'Registration could not be completed. Please try again.'], 500);
        }
    }
}
