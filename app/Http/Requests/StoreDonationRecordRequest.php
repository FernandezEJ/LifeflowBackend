<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDonationRecordRequest extends FormRequest
{
    // ========================================
    // AUTHENTICATED SUBMISSION
    // Sanctum must identify the donor before any record is accepted.
    // ========================================
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    // ========================================
    // DONATION DETAILS
    // Accepts a real calendar date through today and bounded text fields.
    // ========================================
    public function rules(): array
    {
        return [
            'donation_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'location' => ['required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    // ========================================
    // BLOCK SELF-VERIFICATION AND SPOOFED OWNERSHIP
    // Rejects all non-donor fields, including status and verification timestamps.
    // ========================================
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['donation_date', 'location', 'notes']) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        }];
    }
}
