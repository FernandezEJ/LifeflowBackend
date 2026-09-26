<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AccountSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('new_email'))) {
            $this->merge(['new_email' => strtolower(trim($this->input('new_email')))]);
        }
    }

    public function rules(): array
    {
        $token = ['pending_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
        $current = ['current_password' => ['required', 'string', 'max:128']];

        return match ($this->route()->getActionMethod()) {
            'requestCode' => $current + ['new_email' => ['required', 'string', 'email:rfc', 'max:254']],
            'resend' => $token,
            'verify' => $token + ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']],
            'password' => $current + [
                'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && (str_contains($value, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72))) {
                        $fail('Use a password of at most 72 bytes without null characters.');
                    }
                }],
                'password_confirmation' => ['required', 'string', 'max:128'],
            ],
        };
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
