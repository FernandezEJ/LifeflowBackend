<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DonorAchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DonationRecordController extends Controller
{
    // ========================================
    // RETIRED MANUAL DONOR SUBMISSION
    // Kept as an explicit disabled legacy action; there is no donor POST route.
    // ========================================
    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Manual donation submission is retired. Join a donation opportunity instead.'], 410);
    }

    // ========================================
    // PRIVATE DONATION HISTORY
    // Filters match the existing history tabs, with newest donation dates first.
    // ========================================
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'completed', 'rejected'])]]);
        $query = $request->user()->donationRecords();
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->orderByDesc('donation_date')->orderByDesc('id')->paginate(20));
    }

    // ========================================
    // OWNED RECORD DETAILS
    // The existing detail screen cannot retrieve another donor's record by ID.
    // ========================================
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['donation_record' => $request->user()->donationRecords()->findOrFail($id)]);
    }

    // ========================================
    // VERIFIED DONATION SUMMARY
    // Pending and rejected records never count; achievements reflect current completed data.
    // ========================================
    public function summary(Request $request, DonorAchievementService $achievements): JsonResponse
    {
        return response()->json($achievements->summary($request->user()));
    }
}
