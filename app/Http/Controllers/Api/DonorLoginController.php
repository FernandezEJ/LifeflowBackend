<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DonorLoginRequest;
use App\Services\DonorLoginService;
use Illuminate\Http\JsonResponse;

class DonorLoginController extends Controller
{
    public function requestCode(DonorLoginRequest $request, DonorLoginService $service): JsonResponse
    {
        return response()->json($service->request($request->validated('identifier')), 202)->header('Cache-Control', 'no-store');
    }

    public function verify(DonorLoginRequest $request, DonorLoginService $service): JsonResponse
    {
        return response()->json($service->verify($request->validated('identifier'), $request->validated('code')))->header('Cache-Control', 'no-store');
    }

    public function confirmEmail(DonorLoginRequest $request, DonorLoginService $service): JsonResponse
    {
        return response()->json($service->verify($request->validated('identifier'), $request->validated('code'), $request->user()))->header('Cache-Control', 'no-store');
    }
}
