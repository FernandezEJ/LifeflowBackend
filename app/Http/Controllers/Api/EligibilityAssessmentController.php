<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEligibilityAssessmentRequest;
use App\Services\DonationCooldown;
use App\Services\EligibilityCooldown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EligibilityAssessmentController extends Controller
{
    // ========================================
    // SAVE SERVER-EVALUATED PRE-SCREENING
    // Stores answers and every reason in a single insert under the token owner.
    // ========================================
    public function store(StoreEligibilityAssessmentRequest $request, EligibilityCooldown $cooldown): JsonResponse
    {
        $answers = $request->validated('answers');
        try {
            // Return the saved result during cooldown without changing history.
            $result = $cooldown->submit($request->user(), $answers);
            $created = $result['created'];
            unset($result['created']);

            return response()->json([
                'message' => $created ? 'Eligibility pre-screening completed.' : 'You already completed an evaluation recently. Please wait before submitting again.',
                ...$result,
                ...($created ? [] : ['latest_assessment' => $result['assessment']]),
            ], $created ? 201 : 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Pre-screening could not be saved. Please try again.'], 500);
        }
    }

    // ========================================
    // LATEST RESULT
    // A null assessment is an explicit empty state, never an eligible result.
    // ========================================
    public function latest(Request $request, EligibilityCooldown $cooldown): JsonResponse
    {
        $assessment = $request->user()->eligibilityAssessments()->orderByDesc('assessed_at')->orderByDesc('id')->first();

        return response()->json(['message' => $assessment ? 'Latest pre-screening result.' : 'No pre-screening assessment yet.', 'assessment' => $assessment, ...$cooldown->metadata($assessment), ...app(DonationCooldown::class)->metadata($request->user())]);
    }

    // ========================================
    // PRIVATE PAGINATED HISTORY
    // Newest first with an ID tie-breaker; client user IDs never select an account.
    // ========================================
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->eligibilityAssessments()
            ->orderByDesc('assessed_at')->orderByDesc('id')->paginate(20));
    }
}
