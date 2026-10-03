<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminsRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Services\Admin\AdminManagementService;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminManagementController extends Controller
{
    public function index(ListAdminsRequest $request, AdminManagementService $service): JsonResponse
    {
        return response()->json($service->listing($request->validated()))->header('Cache-Control', 'no-store');
    }

    public function update(UpdateAdminRequest $request, int $admin, AdminManagementService $service): JsonResponse
    {
        return response()->json(['admin' => $service->profile($service->update($request->user(), $admin, $request->validated()))]);
    }

    public function deactivate(Request $request, int $admin, AdminManagementService $service): JsonResponse
    {
        return response()->json(['admin' => $service->profile($service->deactivate($request->user(), $admin))]);
    }

    public function reactivate(Request $request, int $admin, AdminManagementService $service): JsonResponse
    {
        return response()->json(['admin' => $service->profile($service->reactivate($request->user(), $admin))]);
    }

    public function sendPasswordReset(Request $request, int $admin, AdminManagementService $service): JsonResponse
    {
        $service->sendPasswordReset($request->user(), $admin);

        return response()->json(['message' => PasswordResetService::NOTICE], 202);
    }
}
