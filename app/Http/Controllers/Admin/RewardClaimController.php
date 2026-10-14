<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardClaimRequest;
use App\Services\Admin\RewardClaimService;
use Illuminate\Http\JsonResponse;

class RewardClaimController extends Controller
{
    public function verify(RewardClaimRequest $request, RewardClaimService $claims): JsonResponse
    {
        return response()->json(['claim' => $claims->verify($request->validated('claim_code'))])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(RewardClaimRequest $request, RewardClaimService $claims): JsonResponse
    {
        return response()->json(['claim' => $claims->confirm($request->user(), $request->validated('claim_code'))])->header('Cache-Control', 'private, no-store');
    }
}
