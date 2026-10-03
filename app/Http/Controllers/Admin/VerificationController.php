<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NeedsRevisionRequest;
use App\Http\Requests\Admin\RejectVerificationRequest;
use App\Http\Requests\Admin\ReviewVerificationRequest;
use App\Models\DonationParticipation;
use App\Services\Admin\VerificationService;
use App\Services\PrivateDonationProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends Controller
{
    public function __construct(private VerificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:all,pending,for_verification,completed,rejected,needs_revision'],
            'month' => ['sometimes', 'integer', 'between:1,12'], 'year' => ['sometimes', 'integer', 'between:1900,9999']]);

        return response()->json($this->service->listing($filters))->header('Cache-Control', 'private, no-store');
    }

    public function show(int $participation): JsonResponse
    {
        return response()->json(['verification' => $this->service->profile(DonationParticipation::findOrFail($participation))])->header('Cache-Control', 'private, no-store');
    }

    public function proof(int $participation, PrivateDonationProof $proof): StreamedResponse
    {
        return $proof->response(DonationParticipation::findOrFail($participation), true);
    }

    public function approve(ReviewVerificationRequest $request, int $participation): JsonResponse
    {
        return $this->review($request, $participation, 'approve');
    }

    public function needsRevision(NeedsRevisionRequest $request, int $participation): JsonResponse
    {
        return $this->review($request, $participation, 'needs-revision');
    }

    public function reject(RejectVerificationRequest $request, int $participation): JsonResponse
    {
        return $this->review($request, $participation, 'reject');
    }

    private function review(ReviewVerificationRequest $request, int $id, string $action): JsonResponse
    {
        return response()->json(['verification' => $this->service->profile($this->service->review($request->user(), $id, $action, $request->validated()))]);
    }
}
