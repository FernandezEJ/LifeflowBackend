<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\DonorProfile;
use App\Models\EligibilityAssessment;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function summary(User $actor, int $months): array
    {
        $donors = User::where('role', 'donor')->whereNull('deactivated_at');
        // Same latest-assessment ordering as EligibilityCooldown. These are saved
        // screening outcomes, not a fresh clinical clearance: no assessment is
        // unassessed (neither bucket), and the 24-hour retake timer is not a result.
        // As on mobile, legacy temporarily_ineligible is not eligible; legacy
        // needs_further_screening is indeterminate and belongs to neither bucket.
        $latest = EligibilityAssessment::select('result')->whereColumn('user_id', 'users.id')
            ->orderByDesc('assessed_at')->orderByDesc('id')->limit(1);
        $eligibility = (clone $donors)->select('id')->selectSub($latest, 'latest_result');
        $counts = DB::query()->fromSub($eligibility, 'latest_assessments')
            ->selectRaw('latest_result, COUNT(*) as total')->groupBy('latest_result')->pluck('total', 'latest_result');
        $blood = DonorProfile::whereIn('user_id', (clone $donors)->select('id'))
            ->selectRaw('blood_type, COUNT(*) as total')->groupBy('blood_type')->pluck('total', 'blood_type');
        $recent = DonationParticipation::with(['user.donorProfile', 'opportunity'])
            ->whereIn('status', ['for_verification', 'needs_revision'])
            ->orderByDesc('proof_uploaded_at')->orderByDesc('updated_at')->orderByDesc('id')->limit(5)->get();

        return [
            'months' => $months,
            'timezone' => config('app.calendar_timezone'),
            'stats' => ['donors' => $donors->count(), 'eligible' => (int) ($counts['eligible'] ?? 0),
                'not_eligible' => (int) ($counts['not_eligible'] ?? 0) + (int) ($counts['temporarily_ineligible'] ?? 0)],
            'donation_activity' => $this->activity($months),
            'blood_types' => collect(['O+', 'A+', 'B+', 'AB+', 'O-', 'A-', 'B-', 'AB-'])
                ->map(fn (string $type): array => ['bloodType' => $type, 'count' => (int) ($blood[$type] ?? 0)])->all(),
            'recent_verifications' => $recent->map(fn (DonationParticipation $item): array => [
                'id' => $item->id, 'donor' => $item->user?->name ?? 'Unavailable donor',
                'bloodType' => $item->user?->donorProfile?->blood_type,
                'event' => $item->opportunity?->title, 'status' => $item->status,
                'proof_uploaded_at' => $item->proof_uploaded_at?->toISOString(),
                'updated_at' => $item->updated_at?->toISOString(),
                'date' => $item->opportunity?->event_date?->toDateString(),
            ])->all(),
            ...($actor->isSuperAdmin() ? ['overview' => $this->overview()] : []),
        ];
    }

    private function activity(int $months): array
    {
        $start = now(config('app.calendar_timezone'))->startOfMonth()->subMonths($months - 1);
        // Preserve trusted history even if a donor was subsequently deactivated
        // or soft-deleted. Undated completions cannot be assigned an invented month.
        $completed = DonationParticipation::where('status', 'completed')
            ->whereHas('user', fn (Builder $query) => $query->withTrashed()->where('role', 'donor'));
        // Match the existing donation summary's legacy-record deduplication.
        $legacy = DonationRecord::where('status', 'completed')
            ->whereHas('user', fn (Builder $query) => $query->withTrashed()->where('role', 'donor'))
            ->where(fn (Builder $query) => $query->whereNull('donation_participation_id')
                ->orWhereNotIn('donation_participation_id', DonationParticipation::where('status', 'completed')->select('id')));
        $activity = [];
        for ($index = 0; $index < $months; $index++) {
            $month = $start->copy()->addMonths($index);
            $from = $month->copy()->utc();
            $until = $month->copy()->addMonth()->utc();
            $count = (clone $completed)->where('verified_at', '>=', $from)->where('verified_at', '<', $until)->count()
                + (clone $legacy)->where('verified_at', '>=', $from)->where('verified_at', '<', $until)->count();
            $activity[] = ['period' => $month->format('Y-m'), 'month' => $month->format('M'), 'donations' => $count];
        }

        return $activity;
    }

    /** Read-only previews for the existing Super Admin widgets; no module writes. */
    private function overview(): array
    {
        $month = now(config('app.calendar_timezone'))->startOfMonth();
        $redemptions = UserVoucher::where('status', 'redeemed')->where('redeemed_at', '>=', $month->copy()->utc())
            ->where('redeemed_at', '<', $month->copy()->addMonth()->utc());
        $top = (clone $redemptions)->select('reward_id')->selectRaw('COUNT(*) as total')
            ->groupBy('reward_id')->orderByDesc('total')->orderBy('reward_id')->with('reward')->first();

        return ['total_admins' => User::where('role', 'admin')->count(),
            'active_admins' => User::where('role', 'admin')->whereNull('deactivated_at')->count(),
            'rewards_summary' => ['total_redemptions' => $redemptions->count(),
                'period_label' => $month->format('F Y').' recorded redemptions',
                'most_redeemed_reward' => $top?->reward?->name],
            'recent_admin_activity' => AuditLog::with('actor')->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get()
                ->map(fn (AuditLog $log): array => ['id' => $log->id, 'actor_name' => $log->actor?->name ?? 'Unavailable admin',
                    'action_label' => ucfirst(str_replace('_', ' ', $log->action)), 'created_at' => $log->created_at->toISOString()])->all()];
    }
}
