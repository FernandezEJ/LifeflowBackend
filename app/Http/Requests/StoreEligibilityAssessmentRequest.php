<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEligibilityAssessmentRequest extends FormRequest
{
    // ========================================
    // AUTHENTICATED SUBMISSION
    // Sanctum identifies the donor before this request is validated.
    // ========================================
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    // ========================================
    // EXACT QUESTIONNAIRE CONTRACT
    // Allows only answers, with strict JSON numeric types and YES/NO choices.
    // Sleep's 24-hour limit is input validity, not an eligibility threshold.
    // ========================================
    public function rules(): array
    {
        $number = function ($attribute, $value, $fail) {
            if (! is_int($value) && ! is_float($value)) {
                $fail('The '.$attribute.' must be a JSON number.');
            }
        };
        $rules = [
            'answers' => ['required', 'array:weight,sleepHours,currentSymptoms,donatedWithinThreeMonths,feelsWell,currentlyPregnant,takingAntibioticsForActiveInfection,stillRecoveringFromProcedure,activeOrRecoveringInfection,weakDizzyOrUnusuallyTired'],
            'answers.weight' => ['required', $number, 'numeric', 'gt:0'],
            'answers.sleepHours' => ['required', $number, 'numeric', 'between:0,24'],
        ];
        foreach (['currentSymptoms', 'donatedWithinThreeMonths', 'feelsWell', 'takingAntibioticsForActiveInfection', 'stillRecoveringFromProcedure', 'activeOrRecoveringInfection', 'weakDizzyOrUnusuallyTired'] as $key) {
            $rules['answers.'.$key] = ['required', 'string', Rule::in(['YES', 'NO'])];
        }

        // Pregnancy is explicitly answered, never inferred from profile data.
        $rules['answers.currentlyPregnant'] = ['required', 'string', Rule::in(['YES', 'NO', 'NOT_APPLICABLE'])];

        return $rules;
    }

    // ========================================
    // REJECT CLIENT RESULTS AND OWNERSHIP
    // Unknown top-level inputs cannot override calculated results or account identity.
    // ========================================
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['answers']) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        }];
    }
}
