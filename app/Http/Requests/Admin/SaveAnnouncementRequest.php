<?php

namespace App\Http\Requests\Admin;

use App\Services\AnnouncementContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveAnnouncementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Global string trimming/null conversion must not alter spaces between
        // formatting runs. Restore only this field from the original JSON body.
        if ($this->isJson()) {
            $original = json_decode($this->getContent(), true);
            if (is_array($original) && array_key_exists('description_document', $original)) {
                $this->merge(['description_document' => $original['description_document']]);
            }
        }
        // Multipart carries the same restricted document as JSON API callers.
        if (is_string($this->input('description_document'))) {
            $decoded = json_decode($this->input('description_document'), true);
            if (is_array($decoded)) {
                $this->merge(['description_document' => $decoded]);
            }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->any() && is_array($this->input('description_document'))) {
                try {
                    AnnouncementContent::plainText($this->input('description_document'));
                } catch (ValidationException $error) {
                    $validator->errors()->add('description_document', $error->getMessage());
                }
            }
        }];
    }

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
            'description_document' => ['nullable', 'array'],
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
