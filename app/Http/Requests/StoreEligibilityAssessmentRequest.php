<?php

namespace App\Http\Requests;

use App\Services\EligibilityEvaluator;
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
        $keys = array_keys(EligibilityEvaluator::FLAGS);
        $rules = ['assessment_version' => ['required', Rule::in([EligibilityEvaluator::VERSION])],
            'answers' => ['required', 'array:'.implode(',', $keys)],
            'follow_ups' => ['sometimes', 'array:procedureType,procedureWhen,medications,transfusionWhen']];
        foreach ($keys as $key) {
            $rules['answers.'.$key] = ['required', 'string', Rule::in(['YES', 'NO'])];
        }

        foreach (['procedureType' => 'procedurePast12Months', 'procedureWhen' => 'procedurePast12Months',
            'medications' => 'medicationsOrSupplements', 'transfusionWhen' => 'everReceivedTransfusion'] as $field => $answer) {
            $rules['follow_ups.'.$field] = [
                Rule::excludeIf(fn () => $this->input('answers.'.$answer) !== 'YES'),
                Rule::requiredIf(fn () => $this->input('answers.'.$answer) === 'YES'),
                'string', 'min:1', 'max:200', 'not_regex:/[\x00-\x1F\x7F<>]/u',
            ];
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
            foreach (array_diff(array_keys($this->all()), ['answers', 'assessment_version', 'follow_ups']) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        }];
    }
}
