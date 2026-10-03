<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DonationParticipationController;
use App\Http\Controllers\Api\DonationRecordController;
use App\Http\Controllers\Api\DonorLoginController;
use App\Http\Controllers\Api\DonorProfileController;
use App\Http\Controllers\Api\EligibilityAssessmentController;
use App\Http\Controllers\Api\FlowieController;
use App\Http\Controllers\Api\FlowieConversationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RegistrationVerificationController;
use App\Http\Controllers\Api\RewardsController;
use App\Http\Middleware\EnsureDonorUser;
use Illuminate\Support\Facades\Route;

// ========================================
// PUBLIC AUTHENTICATION ROUTES
// Creates accounts and exchanges credentials for a mobile API token.
// ========================================
// Legacy registration also starts verification; it cannot create an unverified account.
Route::post('/register', [RegistrationVerificationController::class, 'requestCode'])->middleware('throttle:registration-request');
Route::post('/register/request-verification', [RegistrationVerificationController::class, 'requestCode'])->middleware('throttle:registration-request');
Route::post('/register/verify-email', [RegistrationVerificationController::class, 'verify'])->middleware('throttle:registration-verify');
Route::post('/register/resend-verification', [RegistrationVerificationController::class, 'resend'])->middleware('throttle:registration-request');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
// Donor passwordless authentication is separate from Admin email/password routes.
Route::post('/auth/request-login-code', [DonorLoginController::class, 'requestCode'])->middleware('throttle:donor-login-request');
Route::post('/auth/verify-login-code', [DonorLoginController::class, 'verify'])->middleware('throttle:donor-login-verify');

// Password recovery stays public, with independent IP limits for each step.
Route::post('/forgot-password/request', [PasswordResetController::class, 'requestCode'])->middleware('throttle:password-reset-request');
Route::post('/forgot-password/verify', [PasswordResetController::class, 'verify'])->middleware('throttle:password-reset-verify');
Route::post('/forgot-password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset-finish');

// ========================================
// DONOR SUBMISSION, HISTORY, AND SUMMARY
// No donor route can approve, reject, or modify a submitted donation record.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    // Manual donor creation is retired; records remain read-only historical outcomes.
    Route::get('/donation-records', [DonationRecordController::class, 'index']);
    Route::get('/donation-records/{id}', [DonationRecordController::class, 'show'])->whereNumber('id');
    Route::get('/donation-summary', [DonationRecordController::class, 'summary']);
});

// ========================================
// OPPORTUNITIES AND DONOR PARTICIPATION
// No route permits donor verification or points awards.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/donation-opportunities', [DonationParticipationController::class, 'opportunities']);
    Route::get('/announcements', [DonationParticipationController::class, 'opportunities']);
    Route::get('/announcements/{id}', [DonationParticipationController::class, 'opportunity'])->whereNumber('id');
    Route::get('/donation-opportunities/{id}', [DonationParticipationController::class, 'opportunity'])->where('id', '[0-9]+|red-cross-dagupan');
    Route::post('/donation-opportunities/{id}/join', [DonationParticipationController::class, 'join'])->where('id', '[0-9]+|red-cross-dagupan');
    Route::get('/donation-participations', [DonationParticipationController::class, 'index']);
    Route::get('/donation-participations/{id}', [DonationParticipationController::class, 'show'])->whereNumber('id');
    Route::post('/donation-participations/{id}/cancel', [DonationParticipationController::class, 'cancel'])->whereNumber('id');
    Route::post('/donation-participations/{id}/proof', [DonationParticipationController::class, 'proof'])->whereNumber('id');
    Route::get('/donation-participations/{id}/proof', [DonationParticipationController::class, 'downloadProof'])->whereNumber('id');
});

// ========================================
// PROTECTED PRE-SCREENING ROUTES
// All assessment writes and reads belong to the current Sanctum token owner.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/eligibility-assessments', [EligibilityAssessmentController::class, 'store']);
    Route::get('/eligibility-assessments/latest', [EligibilityAssessmentController::class, 'latest']);
    Route::get('/eligibility-assessments', [EligibilityAssessmentController::class, 'index']);
});

// ========================================
// PROTECTED AUTHENTICATION ROUTES
// Requires a valid Sanctum bearer token to read the user or log out.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// ========================================
// PROTECTED DONOR PROFILE ROUTES
// Uses the authenticated token to access only the current donor's data.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/donor-profile', [DonorProfileController::class, 'show']);
    Route::put('/donor-profile/avatar', [DonorProfileController::class, 'avatar'])->middleware('throttle:30,1');
    Route::put('/donor-profile', [DonorProfileController::class, 'update']);
});

// ========================================
// PRIVATE INBOX AND DEVICE REGISTRATION
// Every endpoint is scoped to Sanctum; numeric IDs cannot select an owner.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/reminders', [NotificationController::class, 'reminders']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
    Route::post('/device-tokens', [DeviceTokenController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/device-tokens/unregister', [DeviceTokenController::class, 'destroy']);
});

// ========================================
// MOBILE POINTS, REWARDS AND OWNED VOUCHERS
// Read/spend/activate only: no donor award, inventory editing or admin routes.
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    $controller = RewardsController::class;
    Route::get('/points/summary', [$controller, 'summary']);
    Route::get('/points/transactions', [$controller, 'transactions']);
    Route::get('/rewards', [$controller, 'rewards']);
    Route::get('/rewards/{id}', [$controller, 'reward'])->whereNumber('id');
    Route::post('/rewards/{id}/redeem', [$controller, 'redeem'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/vouchers', [$controller, 'vouchers']);
    Route::get('/vouchers/{id}', [$controller, 'voucher'])->whereNumber('id');
    Route::post('/vouchers/{id}/activate', [$controller, 'activate'])->whereNumber('id')->middleware('throttle:30,1');
});

Route::middleware(['auth:sanctum', 'throttle:10,1'])->group(function () {
    Route::post('/profile/change-email/confirm-login', [DonorLoginController::class, 'confirmEmail'])->middleware('throttle:donor-login-verify');
    Route::post('/profile/change-email/request', [AccountSettingsController::class, 'requestCode']);
    Route::post('/profile/change-email/resend', [AccountSettingsController::class, 'resend']);
    Route::post('/profile/change-email/verify', [AccountSettingsController::class, 'verify']);
    Route::post('/profile/change-password', [AccountSettingsController::class, 'password']);
});

// Flowie talks; Laravel decides. These donor-only routes persist conversation text, never official outcomes.
Route::middleware(['auth:sanctum', EnsureDonorUser::class])->prefix('flowie')->group(function () {
    Route::post('/chat', [FlowieController::class, 'chat'])->middleware('throttle:10,1,flowie-chat');
    Route::get('/conversations', [FlowieConversationController::class, 'index']);
    Route::get('/conversations/recently-deleted', [FlowieConversationController::class, 'recentlyDeleted']);
    Route::post('/conversations/active/end', [FlowieConversationController::class, 'end'])->middleware('throttle:30,1');
    Route::get('/conversations/{conversation}', [FlowieConversationController::class, 'show'])->whereNumber('conversation');
    Route::post('/conversations/{conversation}/end', [FlowieConversationController::class, 'end'])->whereNumber('conversation')->middleware('throttle:30,1');
    Route::delete('/conversations/{conversation}', [FlowieConversationController::class, 'destroy'])->whereNumber('conversation')->middleware('throttle:30,1');
    Route::post('/conversations/{conversation}/restore', [FlowieConversationController::class, 'restore'])->whereNumber('conversation')->middleware('throttle:30,1');
});
