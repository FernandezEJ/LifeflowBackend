<?php

namespace Tests\Unit;

use App\Services\EligibilityEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EligibilityEvaluatorTest extends TestCase
{
    // Exact boundaries pass; all adverse answers return the same binary result.
    public static function cases(): array
    {
        $cases = ['all passing' => [[], 'basic_requirements_met', 0]];
        $all = [];
        foreach (self::answers() as $key => $passingAnswer) {
            $all[$key] = $passingAnswer === 'YES' ? 'NO' : 'YES';
            $cases[$key] = [[$key => $all[$key]], 'needs_further_review', 1];
        }
        $cases['all ten flags'] = [$all, 'needs_further_review', 1];

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_rules(array $overrides, string $result, int $count): void
    {
        $answers = array_replace(self::answers(), $overrides);
        $evaluation = (new EligibilityEvaluator)->evaluate($answers);
        $this->assertSame($result, $evaluation['result']);
        $this->assertContains($evaluation['result'], ['basic_requirements_met', 'needs_further_review']);
        $this->assertCount($count, $evaluation['reasons']);
        $this->assertSame($evaluation, (new EligibilityEvaluator)->evaluate($answers));
    }

    // These ten answers are the only supported new-submission contract.
    public static function answers(): array
    {
        return ['feverPastSevenDays' => 'NO', 'weightAtLeast50Kg' => 'YES', 'feelingWellToday' => 'YES', 'sleptAtLeastFiveHours' => 'YES', 'eatenMealToday' => 'YES', 'alcoholPast24Hours' => 'NO', 'donatedWithinThreeMonths' => 'NO', 'procedurePast12Months' => 'NO', 'medicationsOrSupplements' => 'NO', 'everReceivedTransfusion' => 'NO'];
    }

    public static function payload(array $overrides = []): array
    {
        $answers = array_replace(self::answers(), $overrides);
        $follow = [];
        if ($answers['procedurePast12Months'] === 'YES') {
            $follow += ['procedureType' => 'Tattoo', 'procedureWhen' => 'About six months ago'];
        }
        if ($answers['medicationsOrSupplements'] === 'YES') {
            $follow['medications'] = 'Test supplement';
        }
        if ($answers['everReceivedTransfusion'] === 'YES') {
            $follow['transfusionWhen'] = 'During childhood';
        }

        return ['assessment_version' => 'v5', 'answers' => $answers, 'follow_ups' => $follow];
    }

    public static function legacyAnswers(): array
    {
        return ['weightAtLeast50Kg' => 'YES', 'sleptAtLeastFiveHours' => 'YES', 'eatenProperMeal' => 'YES',
            'avoidedAlcoholFor24Hours' => 'YES', 'threeMonthsSinceLastDonation' => 'YES',
            'recentFeverInfectionOrIllness' => 'NO', 'unusualBleedingWeaknessOrDizziness' => 'NO',
            'recentSurgeryOrMajorProcedure' => 'NO', 'medicationAffectingDonation' => 'NO',
            'conditionOrTreatmentRequiringWait' => 'NO'];
    }
}
