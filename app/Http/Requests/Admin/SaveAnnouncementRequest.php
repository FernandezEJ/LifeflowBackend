<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'location' => ['required', 'string', 'max:255'],
            'donation_date' => ['required', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:donation_date'],
            'status' => ['sometimes', 'in:draft,published'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'notify_donors' => ['sometimes', 'boolean'],
            'points_reward' => ['missing'],
            'created_by' => ['missing'],
            'source_type' => ['missing'],
            'published_at' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
