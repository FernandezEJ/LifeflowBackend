<?php

namespace App\Services;

class EligibilityEvaluator
{
    // ========================================
    // BINARY PRE-SCREENING RESULT
    // Only these self-reported project factors determine a new result.
    // Every triggered reason is retained; facility screening remains final.
    // ========================================
    public function evaluate(array $answers): array
    {
        $reasons = [];
        if ($answers['weight'] < 50) {
            $reasons[] = 'Your reported weight is below the LifeFlow pre-screening minimum of 50 kg.';
        }
        if ($answers['sleepHours'] < 5) {
            $reasons[] = 'You reported less than 5 hours of sleep last night.';
        }
        foreach ([
            'currentSymptoms' => ['YES', 'You reported current fever, cough, colds, sore throat, or feeling unwell.'],
            'donatedWithinThreeMonths' => ['YES', 'You reported donating blood within the last 3 months.'],
            'feelsWell' => ['NO', 'You reported not feeling well enough to donate today.'],
            'currentlyPregnant' => ['YES', 'You reported that you are currently pregnant.'],
            'takingAntibioticsForActiveInfection' => ['YES', 'You reported currently taking antibiotics for an active infection.'],
            'stillRecoveringFromProcedure' => ['YES', 'You reported still recovering from surgery, a medical procedure, or hospitalization.'],
            'activeOrRecoveringInfection' => ['YES', 'You reported an active infection or that you are still recovering from one.'],
            'weakDizzyOrUnusuallyTired' => ['YES', 'You reported feeling weak, dizzy, unusually tired, or physically unwell today.'],
        ] as $key => [$trigger, $reason]) {
            if ($answers[$key] === $trigger) {
                $reasons[] = $reason;
            }
        }

        return ['result' => $reasons ? 'not_eligible' : 'eligible', 'reasons' => $reasons];
    }
}
