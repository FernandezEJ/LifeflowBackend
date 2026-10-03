<?php

namespace App\Services\Admin;

use App\Models\DonationParticipation;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\PrivateDonationProof;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VerificationService
{
    public function version(DonationParticipation $item): ?string
    {
        return $item->proof_path ? hash('sha256', $item->proof_path.'|'.$item->proof_uploaded_at?->toISOString()) : null;
    }

    public function profile(DonationParticipation $item): array
    {
        $item->loadMissing(['user.donorProfile', 'opportunity']);
        $award = $item->status === 'completed' ? PointTransaction::where('user_id', $item->user_id)
            ->where('event_key', 'donation:'.$item->id.':reward')->first() : null;

        return ['id' => $item->id, 'status' => $item->status, 'source_type' => $item->source_type,
            'donor' => ['id' => $item->user_id, 'name' => $item->user?->name ?? 'Unavailable donor',
                'email' => $item->user?->email, 'blood_type' => $item->user?->donorProfile?->blood_type,
                'mobile_number' => $item->user?->donorProfile?->mobile_number],
            'opportunity' => ['id' => $item->donation_opportunity_id, 'title' => $item->opportunity?->title,
                'location' => $item->opportunity?->location, 'donation_date' => $item->opportunity?->event_date?->toDateString()],
            'joined_at' => $item->joined_at?->toISOString(), 'proof_uploaded_at' => $item->proof_uploaded_at?->toISOString(),
            'verified_at' => $item->verified_at?->toISOString(), 'updated_at' => $item->updated_at?->toISOString(),
            'revision_reason' => $item->revision_reason, 'rejection_reason' => $item->rejection_reason,
            'proof' => $item->proof_path ? ['name' => $item->proof_original_name, 'mime_type' => $item->proof_mime_type,
                'size' => $item->proof_size, 'version' => $this->version($item)] : null,
            'completion' => $item->status === 'completed' ? ['points_awarded' => $award?->amount ?? 0, 'verified_at' => $item->verified_at?->toISOString()] : null];
    }

    public function listing(array $filters): array
    {
        $items = DonationParticipation::with(['user.donorProfile', 'opportunity'])->whereIn('status',
            ['pending', 'for_verification', 'needs_revision', 'completed', 'rejected'])->orderByDesc('id')->get();
        $rows = $items->map(fn (DonationParticipation $item): array => $this->profile($item));
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $rows = $rows->filter(function (array $row) use ($filters, $search): bool {
            $date = Carbon::parse($row['proof_uploaded_at'] ?? $row['joined_at'])->timezone(config('app.calendar_timezone'));

            return (($filters['status'] ?? 'all') === 'all' || $row['status'] === $filters['status'])
                && ($search === '' || str_contains(mb_strtolower(implode(' ', [$row['donor']['name'], $row['donor']['email'], $row['donor']['blood_type'], $row['opportunity']['title']])), $search))
                && (! isset($filters['month']) || $date->month === (int) $filters['month'])
                && (! isset($filters['year']) || $date->year === (int) $filters['year']);
        });

        return ['data' => $rows->values()->all()];
    }

    public function review(User $actor, int $id, string $action, array $data): DonationParticipation
    {
        return DB::transaction(function () use ($actor, $id, $action, $data): DonationParticipation {
            $reviewer = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($reviewer->isAdminPanelUser() && ! $reviewer->isDeactivated() && ! $reviewer->must_change_password, 403);
            $owner = DonationParticipation::whereKey($id)->value('user_id');
            abort_if($owner === null, 404);
            // Match the existing award/join lock order: donor first, then participation.
            User::withTrashed()->whereKey($owner)->lockForUpdate()->firstOrFail();
            $item = DonationParticipation::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'for_verification', 409, 'Only submissions awaiting verification can be reviewed. Refresh this record.');
            abort_unless($this->version($item) !== null && hash_equals($this->version($item), $data['proof_version']), 409, 'The proof changed. Refresh and review the latest submission.');
            app(PrivateDonationProof::class)->path($item);
            $item->status = match ($action) {
                'approve' => 'completed', 'needs-revision' => 'needs_revision', 'reject' => 'rejected'
            };
            $item->revision_reason = $action === 'needs-revision' ? $data['reason'] : null;
            $item->rejection_reason = $action === 'reject' ? $data['reason'] : null;
            $item->verified_at = $action === 'needs-revision' ? null : now();
            // Eloquent's existing observer awards the ledger and sends the outcome exactly once.
            $item->save();
            $event = match ($action) {
                'approve' => 'approved', 'needs-revision' => 'needs_revision', 'reject' => 'rejected'
            };
            $reviewer->auditLogs()->create(['actor_role' => $reviewer->role, 'action' => 'verification_'.$event,
                'module' => 'verification', 'target_type' => 'donation_participation', 'target_id' => $item->id,
                'details' => isset($data['reason']) ? ['reason' => $data['reason']] : null]);

            return $item;
        }, 3);
    }
}
