<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeInitialPasswordRequest;
use App\Http\Requests\Admin\CreateAdminRequest;
use App\Services\Admin\AdminAccountService;
use Illuminate\Http\JsonResponse;

class AdminAccountController extends Controller
{
    public function store(CreateAdminRequest $request, AdminAccountService $service): JsonResponse
    {
        $result = $service->create($request->user(), $request->validated('name'), $request->validated('email'));
        $admin = $result['admin'];

        return response()->json([
            'message' => 'Admin account created.',
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email,
                'role' => $admin->role->value, 'must_change_password' => $admin->must_change_password],
            'temporary_password' => $result['temporary_password'],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function changeInitialPassword(ChangeInitialPasswordRequest $request, AdminAccountService $service): JsonResponse
    {
        $service->changeInitialPassword($request->user(), $request->validated('current_password'), $request->validated('password'));

        return response()->json(['message' => 'Password changed successfully.'])->header('Cache-Control', 'no-store');
    }
}
