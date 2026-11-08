<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEligibilityAssessmentRequest;
use App\Services\DonationCooldown;
use App\Services\EligibilityCooldown;
use App\Services\EligibilityEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        // Conditional fields excluded by validation never survive an answer change.
        $answers = [...$answers, ...($request->validated('follow_ups') ? ['follow_ups' => $request->validated('follow_ups')] : [])];
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
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Do not forward assessment answers, SQL bindings or exception chains to reporters.
            Log::error('Eligibility assessment save failed.', ['exception_class' => $exception::class]);

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
        if (($assessment?->assessment_version !== null || in_array($assessment?->result, ['basic_requirements_met', 'needs_further_review'], true))
            && $request->query('assessment_version') !== EligibilityEvaluator::VERSION) {
            return $this->updateRequired();
        }

        return response()->json(['message' => $assessment ? 'Latest pre-screening result.' : 'No pre-screening assessment yet.', 'assessment' => $assessment, ...$cooldown->metadata($assessment), ...app(DonationCooldown::class)->metadata($request->user())]);
    }

    // ========================================
    // PRIVATE PAGINATED HISTORY
    // Newest first with an ID tie-breaker; client user IDs never select an account.
    // ========================================
    public function index(Request $request): JsonResponse
    {
        if ($request->query('assessment_version') !== EligibilityEvaluator::VERSION
            && $request->user()->eligibilityAssessments()->where(fn ($query) => $query->whereNotNull('assessment_version')
                ->orWhereIn('result', ['basic_requirements_met', 'needs_further_review']))->exists()) {
            return $this->updateRequired();
        }

        return response()->json($request->user()->eligibilityAssessments()
            ->orderByDesc('assessed_at')->orderByDesc('id')->paginate(20));
    }

    private function updateRequired(): JsonResponse
    {
        return response()->json(['message' => 'Please update LifeFlow to view the latest self-assessment.',
            'reason' => 'assessment_version_required', 'required_assessment_version' => EligibilityEvaluator::VERSION], 426);
    }
}
