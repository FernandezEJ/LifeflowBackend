<?php

namespace App\Services;

use App\Models\EligibilityAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EligibilityCooldown
{
    public const HOURS = 24;

    // Server time determines availability. The client only displays these values.
    public function metadata(?EligibilityAssessment $assessment): array
    {
        $now = now();
        $next = $assessment?->assessed_at->copy()->addHours(self::HOURS);
        $remaining = $next ? max(0, (int) ceil($now->diffInSeconds($next, false))) : 0;

        return [
            'cooldown_active' => $remaining > 0,
            'next_allowed_at' => $next?->toISOString(),
            'remaining_seconds' => $remaining,
            'server_time' => $now->toISOString(),
        ];
    }

    // Serialize submissions for this donor before checking history or inserting.
    // A blocked request leaves every saved assessment unchanged.
    public function submit(User $user, array $answers): array
    {
        return DB::transaction(function () use ($user, $answers) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $latest = $user->eligibilityAssessments()
                ->orderByDesc('assessed_at')->orderByDesc('id')->lockForUpdate()->first();
            $metadata = $this->metadata($latest);
            if ($metadata['cooldown_active']) {
                return ['created' => false, 'assessment' => $latest, ...$metadata];
            }
            // Each permitted submission adds history; it never replaces a result.
            $assessment = $user->eligibilityAssessments()->create([
                ...app(EligibilityEvaluator::class)->evaluate($answers),
                'answers' => $answers,
                'assessed_at' => now()->startOfSecond(),
            ]);

            return ['created' => true, 'assessment' => $assessment, ...$this->metadata($assessment)];
        }, 3);
    }
}
