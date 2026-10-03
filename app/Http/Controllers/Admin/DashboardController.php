<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Services\Admin\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, DashboardService $service): JsonResponse
    {
        return response()->json($service->summary($request->user(), (int) ($request->validated()['months'] ?? 6)));
    }
}
