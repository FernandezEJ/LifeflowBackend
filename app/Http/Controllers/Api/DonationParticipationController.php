<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DonationOpportunity;
use App\Services\DonationCooldown;
use App\Services\EligibilityCooldown;
use App\Services\PrivateDonationProof;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DonationParticipationController extends Controller
{
    // ========================================
    // ACTIVE ANNOUNCEMENTS
    // Newest published post is pinned by the mobile board; no fake fallback row.
    // ========================================
    public function opportunities()
    {
        return response()->json(['data' => DonationOpportunity::active()->orderByDesc('published_at')->orderByDesc('id')->get()]);
    }

    public function opportunity(string $id)
    {
        if ($id === 'red-cross-dagupan') {
            return response()->json(['opportunity' => DonationOpportunity::redCrossDagupan()]);
        }
        abort_unless(ctype_digit($id), 404);

        return response()->json(['opportunity' => DonationOpportunity::active()->findOrFail($id)]);
    }

    // Both sources use this same join, proof and verification lifecycle.
    // Donor-first locking serializes simultaneous joins of the permanent source.
    public function join(Request $request, string $id)
    {
        $this->only($request, []);
        $redCross = $id === 'red-cross-dagupan';
        abort_unless($redCross || ctype_digit($id), 404);

        return DB::transaction(function () use ($request, $id, $redCross) {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            // One active activity across both sources; use the same donor lock as assessments.
            $active = $user->donationParticipations()->active()
                ->orderByDesc('joined_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($active !== null) {
                return response()->json([
                    'reason' => 'active_participation_exists',
                    'message' => 'Complete or cancel your current activity before joining another donation opportunity.',
                    'participation_id' => $active->id,
                ], 409);
            }
            $rest = app(DonationCooldown::class)->metadata($user, true);
            if ($rest['is_on_donation_cooldown']) {
                return response()->json([
                    'reason' => 'donation_cooldown_active',
                    'message' => 'You are currently in your donation rest period. You can donate again starting '.CarbonImmutable::parse($rest['next_eligible_donation_at'])->format('F j, Y').'.',
                    ...$rest,
                ], 409);
            }
            $latest = $user->eligibilityAssessments()
                ->orderByDesc('assessed_at')->orderByDesc('id')->lockForUpdate()->first();
            $metadata = app(EligibilityCooldown::class)->metadata($latest);
            if (! $metadata['cooldown_active']) {
                return response()->json([
                    'reason' => 'evaluation_required',
                    'message' => 'You need a current LifeFlow self-assessment before joining this donation activity.',
                    ...$metadata,
                ], 409);
            }
            // Legacy non-eligible results never grant permission either.
            if ($latest->result !== 'eligible') {
                return response()->json([
                    'reason' => 'evaluation_not_eligible',
                    'message' => 'Your latest pre-screening is still active. You can reassess when the 24-hour window ends. Final eligibility is determined by the donation facility.',
                    ...$metadata,
                ], 409);
            }
            $opportunity = $redCross ? null : DonationOpportunity::active()->lockForUpdate()->findOrFail($id);
            $completed = ! $redCross && $user->donationParticipations()
                ->where('donation_opportunity_id', $opportunity->id)
                ->where('status', 'completed')->lockForUpdate()->first() !== null;
            abort_if($completed, 409, 'You already completed this donation opportunity.');
            $participation = $user->donationParticipations()->make();
            $participation->donation_opportunity_id = $opportunity?->id;
            $participation->source_type = $redCross ? 'red_cross_dagupan' : 'admin_announcement';
            $participation->status = 'pending';
            $participation->joined_at = now();
            $participation->save();

            return response()->json(['participation' => $participation->load('opportunity')], 201);
        }, 3);
    }

    // ========================================
    // PRIVATE ACTIVITY HISTORY
    // Opportunity details remain accessible through owned activity after expiry.
    // ========================================
    public function index(Request $request)
    {
        $input = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'for_verification', 'needs_revision', 'completed', 'rejected', 'cancelled'])]]);
        $query = $request->user()->donationParticipations()->with('opportunity');
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        return response()->json($query->orderByDesc('joined_at')->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['participation' => $request->user()->donationParticipations()->with('opportunity')->findOrFail($id)]);
    }

    // ========================================
    // CANCEL BEFORE PROOF
    // Row locks prevent cancellation racing with proof submission.
    // ========================================
    public function cancel(Request $request, int $id)
    {
        $this->only($request, []);

        return DB::transaction(function () use ($request, $id) {
            $item = $request->user()->donationParticipations()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'pending', 409, 'Only pending participation can be cancelled.');
            $item->status = 'cancelled';
            $item->cancelled_at = now();
            $item->save();

            return response()->json(['participation' => $item->load('opportunity')]);
        });
    }

    // Actual file bytes are validated and stored privately; no external URLs are accepted.
    public function proof(Request $request, int $id)
    {
        $this->only($request, ['proof']);
        $path = null;
        try {
            return DB::transaction(function () use ($request, $id, &$path) {
                $item = $request->user()->donationParticipations()->lockForUpdate()->findOrFail($id);
                abort_unless(in_array($item->status, ['pending', 'needs_revision'], true), 409, 'Only pending or needs-revision participation accepts proof.');
                $oldPath = $item->proof_path;
                $request->validate(['proof' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120']]);
                $file = $request->file('proof');
                abort_unless($file->getSize() > 0, 422, 'Choose a non-empty proof file.');
                $mime = $file->getMimeType();
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime];
                $directory = 'donation-proofs/'.$request->user()->id.'/'.$item->id;
                $path = $directory.'/'.Str::uuid().'.'.$extension;
                $stored = Storage::disk('proofs')->putFileAs($directory, $file, basename($path));
                if ($stored === false) {
                    throw new \RuntimeException('Private proof storage failed.');
                }
                $item->proof_path = $path;
                $item->proof_size = $file->getSize();
                $item->proof_original_name = mb_substr(preg_replace('~[/\\\\\\x00-\\x1F\\x7F]~', '_', $file->getClientOriginalName()), 0, 255);
                $item->proof_mime_type = $mime;
                $item->proof_uploaded_at = now();
                $item->status = 'for_verification';
                $item->revision_reason = null;
                $item->save();
                // Delete only this activity's previous private file, after the replacement commits.
                $prefix = $directory.'/';
                if (is_string($oldPath) && str_starts_with($oldPath, $prefix)
                    && preg_match('~^[a-f0-9-]{36}\\.(jpg|png|pdf)$~D', substr($oldPath, strlen($prefix)))) {
                    DB::afterCommit(function () use ($oldPath): void {
                        try {
                            if (! Storage::disk('proofs')->delete($oldPath)) {
                                Log::warning('Previous proof cleanup did not complete.');
                            }
                        } catch (\Throwable) {
                            // Cleanup failure must not undo or delete the committed replacement.
                            Log::warning('Previous proof cleanup did not complete.');
                        }
                    });
                }

                return response()->json(['participation' => $item->load('opportunity')]);
            });
        } catch (\Throwable $error) {
            // Rollback cannot undo filesystem writes. Remove only this attempt's generated file.
            if ($path !== null) {
                Storage::disk('proofs')->delete($path);
            }
            throw $error;
        }
    }

    // Donors may download only their own private proof.
    public function downloadProof(Request $request, int $id)
    {
        $item = $request->user()->donationParticipations()->findOrFail($id);

        return app(PrivateDonationProof::class)->response($item);
    }

    // ========================================
    // STRICT DONOR INPUT
    // Prevents client-provided owner, status, verification, and reward fields.
    // ========================================
    private function only(Request $request, array $allowed): void
    {
        $extra = array_diff(array_keys($request->all()), $allowed);
        if ($extra) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'This field is not allowed.'));
        }
    }
}
