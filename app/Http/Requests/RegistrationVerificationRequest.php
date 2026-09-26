<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegistrationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['pending_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
        if ($this->route()->getActionMethod() === 'verify') {
            $rules['code'] = ['required', 'string', 'regex:/\A[0-9]{6}\z/'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        }];
    }
}
