<?php

namespace App\Http\Requests;

use App\Services\DonorIdentity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DonorLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['mobile_number' => ['required', 'string', 'regex:/^\+639[0-9]{9}$/D']];
        if ($this->route()->getActionMethod() !== 'requestCode') {
            $rules['code'] = ['required', 'string', 'regex:/^[0-9]{6}$/D'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mobile_number'))) {
            $this->merge(['mobile_number' => DonorIdentity::mobile($this->input('mobile_number'))]);
        }
    }
}
