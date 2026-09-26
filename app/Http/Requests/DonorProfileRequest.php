<?php

namespace App\Http\Requests;

use App\Services\RegistrationVerificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DonorProfileRequest extends FormRequest
{
    // ========================================
    // REQUEST ACCESS
    // Registration is public; the profile route enforces Sanctum authentication.
    // ========================================
    public function authorize(): bool
    {
        return true;
    }

    // ========================================
    // MOBILE NUMBER NORMALIZATION
    // Accepts the frontend's spacing and punctuation, then converts local
    // numbers to +63 format before uniqueness checks and database storage.
    // ========================================
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post') && is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('mobile_number'))) {
            $mobile = preg_replace('/[\s()-]/', '', $this->input('mobile_number'));
            if (preg_match('/^09[0-9]{9}$/D', $mobile)) {
                $mobile = '+63'.substr($mobile, 1);
            }
            $this->merge(['mobile_number' => $mobile]);
        }
    }

    // ========================================
    // ACCOUNT AND PROFILE VALIDATION
    // Registration requires full details. Updates validate only supplied fields,
    // excluding the authenticated owner's records from uniqueness checks.
    // ========================================
    public function rules(): array
    {
        $registering = $this->isMethod('post');
        $required = $registering ? ['required'] : ['sometimes', 'required'];
        $user = $registering ? null : $this->user();

        $rules = [
            'first_name' => [...$required, 'string', 'max:80'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => [...$required, 'string', 'max:80'],
            'email' => $registering ? ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')] : ['missing'],
            'mobile_number' => [...$required, 'string', 'regex:/^\+639[0-9]{9}$/D', Rule::unique('donor_profiles', 'mobile_number')->ignore($user?->donorProfile?->id)],
            'birth_date' => [...$required, 'date_format:Y-m-d', 'before:today'],
            'gender' => [...$required, Rule::in(['Male', 'Female'])],
            'blood_type' => [...$required, Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'user_id' => ['prohibited'],
        ];

        if ($registering) {
            // Required JSON booleans, not Laravel's broader accepted string values.
            foreach (RegistrationVerificationService::CONSENT_FIELDS as $field) {
                $rules[$field] = ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== true) {
                        $fail('This acknowledgement must be accepted.');
                    }
                }];
            }
            foreach (['accepted_at', 'terms_version', 'privacy_version'] as $field) {
                $rules[$field] = ['prohibited'];
            }

            $rules['password'] = ['required', 'string', Password::min(8), 'confirmed', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (str_contains($value, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72))) {
                    $fail('Use a password of at most 72 bytes without null characters.');
                }
            }];
            $rules['password_confirmation'] = ['required', 'string'];
        }

        return $rules;
    }
}
