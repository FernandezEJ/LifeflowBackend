<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SystemReportRequest;
use App\Services\Admin\SystemReportService;
use Illuminate\Http\JsonResponse;

class SystemReportController extends Controller
{
    public function __invoke(SystemReportRequest $request, SystemReportService $reports): JsonResponse
    {
        return response()->json($reports->monthly($request->validated()));
    }
}
