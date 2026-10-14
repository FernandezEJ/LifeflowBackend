<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RewardClaimRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('claim_code'))) {
            $this->merge(['claim_code' => strtoupper(trim($this->input('claim_code')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['claim_code' => ['bail', 'required', 'string', 'size:10', 'regex:/\ALFW-[A-Z0-9]{6}\z/']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['claim_code'])) {
                $validator->errors()->add('request', 'Unsupported request fields.');
            }
        }];
    }
}
