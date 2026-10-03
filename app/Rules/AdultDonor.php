<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class AdultDonor implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Calendar birthdays in Manila, independently of the device's date picker.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            && $value > CarbonImmutable::now('Asia/Manila')->startOfDay()->subYearsNoOverflow(18)->toDateString()) {
            $fail('You must be at least 18 years old to register for LifeFlow.');
        }
    }
}
