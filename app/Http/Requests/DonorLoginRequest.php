<?php

namespace App\Http\Requests;

use App\Services\DonorIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DonorLoginRequest extends FormRequest
{
    private bool $legacyMobileInput = false;

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
        $rules = [
            'identifier' => ['bail', 'required', 'string', 'max:254', function (string $attribute, mixed $value, Closure $fail): void {
                if (! preg_match('/^\+639[0-9]{9}$/D', $value) && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('Enter a valid email or Philippine contact number.');
                }
            }],
            'mobile_number' => $this->legacyMobileInput ? ['required', 'string', 'regex:/^\+639[0-9]{9}$/D'] : ['prohibited'],
        ];
        if ($this->route()->getActionMethod() !== 'requestCode') {
            $rules['code'] = ['required', 'string', 'regex:/^[0-9]{6}$/D'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->legacyMobileInput = ! $this->exists('identifier') && $this->exists('mobile_number');
        if ($this->legacyMobileInput) {
            $mobile = $this->input('mobile_number');
            $mobile = is_string($mobile) ? DonorIdentity::mobile($mobile) : $mobile;
            $this->merge(['identifier' => $mobile, 'mobile_number' => $mobile]);
        } elseif (is_string($this->input('identifier'))) {
            $this->merge(['identifier' => DonorIdentity::loginIdentifier($this->input('identifier'))]);
        }
    }
}
