<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardFiltersRequest;
use App\Http\Requests\Admin\RewardRequest;
use App\Models\Reward;
use App\Services\Admin\RewardAnalyticsService;
use App\Services\Admin\RewardManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function index(RewardFiltersRequest $request, RewardManagementService $service): JsonResponse
    {
        return response()->json($service->listing($request->validated()));
    }

    public function show(int $reward, RewardManagementService $service): JsonResponse
    {
        return response()->json(['reward' => $service->profile(Reward::withTrashed()->findOrFail($reward))]);
    }

    public function store(RewardRequest $request, RewardManagementService $service): JsonResponse
    {
        return response()->json(['reward' => $service->profile($service->save($request->user(), $request->validated()))], 201);
    }

    public function update(RewardRequest $request, int $reward, RewardManagementService $service): JsonResponse
    {
        return response()->json(['reward' => $service->profile($service->save($request->user(), $request->validated(), $reward))]);
    }

    public function destroy(Request $request, int $reward, RewardManagementService $service): JsonResponse
    {
        return response()->json(['reward' => $service->profile($service->transition($request->user(), $reward, 'delete'))]);
    }

    public function restore(Request $request, int $reward, RewardManagementService $service): JsonResponse
    {
        return response()->json(['reward' => $service->profile($service->transition($request->user(), $reward, 'restore'))]);
    }

    public function analytics(RewardFiltersRequest $request, RewardAnalyticsService $service): JsonResponse
    {
        return response()->json($service->summary($request->validated()));
    }

    public function redemptions(RewardFiltersRequest $request, RewardAnalyticsService $service): JsonResponse
    {
        return response()->json($service->redemptions($request->validated()));
    }
}
