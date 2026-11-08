<?php

namespace App\Services;

class EligibilityEvaluator
{
    public const VERSION = 'v5';

    public const FLAGS = [
        'feverPastSevenDays' => 'YES',
        'weightAtLeast50Kg' => 'NO',
        'feelingWellToday' => 'NO',
        'sleptAtLeastFiveHours' => 'NO',
        'eatenMealToday' => 'NO',
        'alcoholPast24Hours' => 'YES',
        'donatedWithinThreeMonths' => 'YES',
        'procedurePast12Months' => 'YES',
        'medicationsOrSupplements' => 'YES',
        'everReceivedTransfusion' => 'YES',
    ];

    /** Evaluate only the approved self-reported answers; facility screening remains final. */
    public function evaluate(array $answers): array
    {
        $reasons = [];
        foreach (self::FLAGS as $key => $flag) {
            if ($answers[$key] === $flag) {
                $reasons[] = $key;
            }
        }

        // Never repeat follow-up health details in summaries or derived consumers.
        return ['result' => $reasons ? 'needs_further_review' : 'basic_requirements_met',
            'reasons' => $reasons ? ['One or more answers should be discussed with qualified blood donation personnel. You may still be able to donate after professional review.'] : []];
    }
}
