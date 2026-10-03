<?php

namespace App\Http\Requests;

use App\Models\DonorProfile;
use App\Rules\AdultDonor;
use App\Services\DonorIdentity;
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
            $this->merge(['mobile_number' => DonorIdentity::mobile($this->input('mobile_number'))]);
        }
        // Keep middle_name for compatibility; new donor input stores an uppercase initial.
        if (is_string($this->input('middle_name'))) {
            $this->merge(['middle_name' => mb_strtoupper(trim($this->input('middle_name'))) ?: null]);
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
            'middle_name' => ['sometimes', 'nullable', 'string', 'regex:/^\p{L}$/uD'],
            'last_name' => [...$required, 'string', 'max:80'],
            'email' => $registering ? ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')] : ['missing'],
            'mobile_number' => [...$required, 'string', 'regex:/^\+639[0-9]{9}$/D', Rule::unique('donor_profiles', 'mobile_number')->ignore($user?->donorProfile?->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (is_string($value) && DonorProfile::whereIn('mobile_number', DonorIdentity::variants($value))
                        ->when($user, fn ($query) => $query->where('user_id', '!=', $user->id))->exists()) {
                        $fail('The mobile number has already been taken.');
                    }
                }],
            'birth_date' => [...$required, 'date_format:Y-m-d', new AdultDonor],
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

            // Old clients may still supply a password. Passwordless clients omit it.
            $rules['password'] = ['sometimes', 'string', Password::min(8), 'confirmed', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (str_contains($value, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72))) {
                    $fail('Use a password of at most 72 bytes without null characters.');
                }
            }];
            $rules['password_confirmation'] = ['required_with:password', 'string'];
        }

        return $rules;
    }
}
