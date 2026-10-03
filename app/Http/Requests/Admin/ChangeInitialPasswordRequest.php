<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeInitialPasswordRequest extends FormRequest
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
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password',
                Password::min(10)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('Use a password of at most 72 bytes without null characters.');
                    }
                }],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
