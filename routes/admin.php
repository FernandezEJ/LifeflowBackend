<?php

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RewardController;
use App\Http\Controllers\Admin\VerificationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');

Route::middleware(['auth:sanctum', 'admin.panel'])->group(function (): void {
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::post('/change-initial-password', [AdminAccountController::class, 'changeInitialPassword'])->middleware('throttle:5,1');

    Route::middleware('admin.password-changed')->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/rewards/analytics', [RewardController::class, 'analytics']);
        Route::get('/rewards/redemptions', [RewardController::class, 'redemptions']);
        Route::get('/rewards', [RewardController::class, 'index']);
        Route::post('/rewards', [RewardController::class, 'store']);
        Route::get('/rewards/{reward}', [RewardController::class, 'show'])->whereNumber('reward');
        Route::put('/rewards/{reward}', [RewardController::class, 'update'])->whereNumber('reward');
        Route::delete('/rewards/{reward}', [RewardController::class, 'destroy'])->whereNumber('reward');
        Route::post('/rewards/{reward}/restore', [RewardController::class, 'restore'])->whereNumber('reward');
        Route::get('/verifications', [VerificationController::class, 'index']);
        Route::get('/verifications/{participation}', [VerificationController::class, 'show'])->whereNumber('participation');
        Route::get('/verifications/{participation}/proof', [VerificationController::class, 'proof'])->whereNumber('participation');
        Route::post('/verifications/{participation}/approve', [VerificationController::class, 'approve'])->whereNumber('participation');
        Route::post('/verifications/{participation}/needs-revision', [VerificationController::class, 'needsRevision'])->whereNumber('participation');
        Route::post('/verifications/{participation}/reject', [VerificationController::class, 'reject'])->whereNumber('participation');
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->whereNumber('announcement');
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->whereNumber('announcement');
        Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->whereNumber('announcement');
        Route::post('/announcements/{announcement}/draft', [AnnouncementController::class, 'draft'])->whereNumber('announcement');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->whereNumber('announcement');
        Route::post('/announcements/{announcement}/restore', [AnnouncementController::class, 'restore'])->whereNumber('announcement');
        Route::middleware('admin.panel:super_admin')->group(function (): void {
            Route::get('/admins', [AdminManagementController::class, 'index']);
            Route::post('/admins', [AdminAccountController::class, 'store']);
            Route::put('/admins/{admin}', [AdminManagementController::class, 'update'])->whereNumber('admin');
            Route::post('/admins/{admin}/deactivate', [AdminManagementController::class, 'deactivate'])->whereNumber('admin');
            Route::post('/admins/{admin}/reactivate', [AdminManagementController::class, 'reactivate'])->whereNumber('admin');
            Route::post('/admins/{admin}/send-password-reset', [AdminManagementController::class, 'sendPasswordReset'])
                ->whereNumber('admin')->middleware('throttle:5,1');
        });
    });
});
