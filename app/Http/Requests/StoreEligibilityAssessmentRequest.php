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
    // Allows only the ten approved answers, with strict YES/NO choices.
    // ========================================
    public function rules(): array
    {
        $keys = ['weightAtLeast50Kg', 'sleptAtLeastFiveHours', 'eatenProperMeal', 'avoidedAlcoholFor24Hours', 'threeMonthsSinceLastDonation', 'recentFeverInfectionOrIllness', 'unusualBleedingWeaknessOrDizziness', 'recentSurgeryOrMajorProcedure', 'medicationAffectingDonation', 'conditionOrTreatmentRequiringWait'];
        $rules = ['answers' => ['required', 'array:'.implode(',', $keys)]];
        foreach ($keys as $key) {
            $rules['answers.'.$key] = ['required', 'string', Rule::in(['YES', 'NO'])];
        }

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
