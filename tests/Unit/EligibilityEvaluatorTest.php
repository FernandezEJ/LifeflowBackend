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
        $cases = ['all passing' => [[], 'eligible', 0]];
        $all = [];
        foreach (self::answers() as $key => $passingAnswer) {
            $all[$key] = $passingAnswer === 'YES' ? 'NO' : 'YES';
            $cases[$key] = [[$key => $all[$key]], 'not_eligible', 1];
        }
        $cases['all ten reasons'] = [$all, 'not_eligible', 10];

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_rules(array $overrides, string $result, int $count): void
    {
        $answers = array_replace(self::answers(), $overrides);
        $evaluation = (new EligibilityEvaluator)->evaluate($answers);
        $this->assertSame($result, $evaluation['result']);
        $this->assertContains($evaluation['result'], ['eligible', 'not_eligible']);
        $this->assertCount($count, $evaluation['reasons']);
        $this->assertSame($evaluation, (new EligibilityEvaluator)->evaluate($answers));
    }

    // These ten answers are the only supported new-submission contract.
    public static function answers(): array
    {
        return ['weightAtLeast50Kg' => 'YES', 'sleptAtLeastFiveHours' => 'YES', 'eatenProperMeal' => 'YES', 'avoidedAlcoholFor24Hours' => 'YES', 'threeMonthsSinceLastDonation' => 'YES', 'recentFeverInfectionOrIllness' => 'NO', 'unusualBleedingWeaknessOrDizziness' => 'NO', 'recentSurgeryOrMajorProcedure' => 'NO', 'medicationAffectingDonation' => 'NO', 'conditionOrTreatmentRequiringWait' => 'NO'];
    }
}
