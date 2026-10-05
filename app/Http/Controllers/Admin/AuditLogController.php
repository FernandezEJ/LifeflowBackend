<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogFiltersRequest;
use App\Services\Admin\AuditLogService;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(AuditLogFiltersRequest $request, AuditLogService $service): JsonResponse
    {
        return response()->json($service->listing($request->validated()))->header('Cache-Control', 'private, no-store');
    }
}
