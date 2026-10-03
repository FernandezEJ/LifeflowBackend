<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class FlowieDonorContextService
{
    public function __construct(
        private readonly DonationCooldown $donationCooldown,
        private readonly EligibilityCooldown $assessmentCooldown,
        private readonly PointsService $points,
        private readonly DonorAchievementService $achievements,
    ) {}

    /**
     * Read-only, request-time snapshot. Query relationships afresh rather than using cached model relations.
     * The caller holds E1's donor lock while taking this snapshot, never while calling Groq.
     *
     * @return array<string, bool|int|string|null>
     */
    public function forDonor(User $user): array
    {
        abort_unless($user->exists && $user->isDonor() && ! $user->isDeactivated() && ! $user->trashed(), 403, 'Only active donors may use Flowie.');

        $profile = $user->donorProfile()->first(['first_name', 'blood_type']);
        $assessment = $user->eligibilityAssessments()->orderByDesc('assessed_at')->orderByDesc('id')
            ->first(['result', 'assessed_at']);
        $freshness = $this->assessmentCooldown->metadata($assessment);
        $rest = $this->donationCooldown->metadata($user);
        $participation = $user->donationParticipations()->active()->orderByDesc('joined_at')->orderByDesc('id')
            ->with('opportunity:id,title')->first(['id', 'donation_opportunity_id', 'source_type', 'status']);
        $summary = $this->achievements->summary($user);

        // Explicit scalar allowlist: never serialize a profile, assessment, activity or ledger record.
        return [
            'first_name' => $this->displayText($profile?->first_name, 80),
            'blood_type' => $profile?->blood_type,
            'latest_assessment_result' => $assessment?->result,
            'assessment_completed_at' => $assessment?->assessed_at?->toISOString(),
            'assessment_is_current' => $assessment ? $freshness['cooldown_active'] : null,
            'is_on_donation_cooldown' => $rest['is_on_donation_cooldown'],
            'next_eligible_donation_at' => $rest['next_eligible_donation_at'],
            'active_participation_status' => $participation?->status,
            'active_participation_opportunity_title' => $this->displayText($participation?->opportunity?->title, 120),
            'completed_donation_count' => $summary['total_donations'],
            'blood_points_balance' => $this->points->summary($user->id)['current_balance'],
            'current_achievement' => $summary['achievement']['label'],
        ];
    }

    private function displayText(?string $value, int $limit): ?string
    {
        $text = Str::squish($value ?? '');

        return $text !== '' ? Str::limit($text, $limit, '') : null;
    }
}
