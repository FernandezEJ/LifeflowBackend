<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DonorProfileRequest;
use App\Http\Requests\UpdateProfileAvatarRequest;
use App\Models\DonorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class DonorProfileController extends Controller
{
    // ========================================
    // CURRENT DONOR PROFILE
    // Uses the token's account to find personal details, including older accounts
    // that do not yet have a profile. Sensitive account fields remain hidden.
    // ========================================
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->donorProfile()->first();

        if (! $profile) {
            return response()->json(['message' => 'Donor profile not found.'], 404);
        }

        return response()->json(['user' => $user, 'donor_profile' => $profile]);
    }

    // ========================================
    // UPDATE ACCOUNT AND PROFILE
    // Updates only validated fields on the token owner's records in one transaction.
    // Locks both records to keep the account name consistent during simultaneous edits.
    // ========================================
    public function update(DonorProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            return DB::transaction(function () use ($request, $validated) {
                $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
                $profile = $user->donorProfile()->lockForUpdate()->first();

                if (! $profile) {
                    return response()->json(['message' => 'Donor profile not found.'], 404);
                }

                $profile->fill(Arr::except($validated, ['email']));
                $user->name = DonorProfile::accountName($profile->getAttributes());
                $user->save();
                $profile->save();

                return response()->json([
                    'message' => 'Profile updated successfully',
                    'user' => $user,
                    'donor_profile' => $profile,
                ]);
            });
        } catch (Throwable $exception) {
            // ========================================
            // SAFE UPDATE FAILURE
            // Leaves both records unchanged and keeps internal errors out of JSON.
            // ========================================
            report($exception);

            return response()->json(['message' => 'Profile could not be updated. Please try again.'], 500);
        }
    }

    // ========================================
    // PROFILE AVATAR
    // Only the authenticated donor's stable mascot identifier can change.
    // Uses the same donor-first locking order as other profile updates.
    // ========================================
    public function avatar(UpdateProfileAvatarRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            $profile = $user->donorProfile()->lockForUpdate()->first();
            if (! $profile) {
                return response()->json(['message' => 'Donor profile not found.'], 404);
            }
            $profile->profile_avatar = $request->validated('profile_avatar');
            $profile->save();

            return response()->json(['message' => 'Avatar saved.', 'user' => $user, 'donor_profile' => $profile]);
        }, 3);
    }
}
