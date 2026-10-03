<?php

namespace App\Services;

class EligibilityEvaluator
{
    /** Evaluate only the approved self-reported answers; facility screening remains final. */
    public function evaluate(array $answers): array
    {
        $reasons = [];
        foreach ([
            'weightAtLeast50Kg' => ['YES', 'Your reported weight does not meet the 50 kg pre-screening minimum.'],
            'sleptAtLeastFiveHours' => ['YES', 'You reported less than 5 hours of sleep before your planned donation.'],
            'eatenProperMeal' => ['YES', 'You reported not eating a proper meal before your planned donation.'],
            'avoidedAlcoholFor24Hours' => ['YES', 'You reported drinking alcohol within the last 24 hours.'],
            'threeMonthsSinceLastDonation' => ['YES', 'You reported that 3 months have not passed since your last completed blood donation.'],
            'recentFeverInfectionOrIllness' => ['NO', 'You reported recent fever, infection, or illness.'],
            'unusualBleedingWeaknessOrDizziness' => ['NO', 'You reported recent unusual bleeding, severe weakness, or dizziness.'],
            'recentSurgeryOrMajorProcedure' => ['NO', 'You reported recent surgery or a major medical or dental procedure.'],
            'medicationAffectingDonation' => ['NO', 'You reported taking medication that may affect blood donation.'],
            'conditionOrTreatmentRequiringWait' => ['NO', 'You reported a recent condition or treatment for which a donation facility advised waiting.'],
        ] as $key => [$passingAnswer, $reason]) {
            if ($answers[$key] !== $passingAnswer) {
                $reasons[] = $reason;
            }
        }

        return ['result' => $reasons ? 'not_eligible' : 'eligible', 'reasons' => $reasons];
    }
}
