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
        $cases = [
            'boundary eligible' => [[], 'eligible', 0],
            'low weight' => [['weight' => 49.9], 'not_eligible', 1],
            'low sleep' => [['sleepHours' => 4.9], 'not_eligible', 1],
            'pregnancy no' => [['currentlyPregnant' => 'NO'], 'eligible', 0],
        ];
        foreach (['currentSymptoms', 'donatedWithinThreeMonths', 'currentlyPregnant', 'takingAntibioticsForActiveInfection',
            'stillRecoveringFromProcedure', 'activeOrRecoveringInfection', 'weakDizzyOrUnusuallyTired'] as $key) {
            $cases[$key] = [[$key => 'YES'], 'not_eligible', 1];
        }
        $cases['unwell'] = [['feelsWell' => 'NO'], 'not_eligible', 1];
        $all = array_fill_keys(array_keys(self::answers()), 'YES');
        $all['weight'] = 49;
        $all['sleepHours'] = 4;
        $all['feelsWell'] = 'NO';
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
        return ['weight' => 50, 'sleepHours' => 5, 'currentSymptoms' => 'NO', 'donatedWithinThreeMonths' => 'NO',
            'feelsWell' => 'YES', 'currentlyPregnant' => 'NOT_APPLICABLE', 'takingAntibioticsForActiveInfection' => 'NO',
            'stillRecoveringFromProcedure' => 'NO', 'activeOrRecoveringInfection' => 'NO', 'weakDizzyOrUnusuallyTired' => 'NO'];
    }
}
