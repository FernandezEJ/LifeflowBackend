<?php

namespace App\Http\Requests\Admin;

use App\Models\EducationalArticle;
use App\Services\AnnouncementContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveEducationalArticleRequest extends SaveAnnouncementRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(EducationalArticle::CATEGORIES))],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'description_document' => ['sometimes', 'nullable', 'array'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'image_path' => ['missing'], 'deleted_at' => ['missing'], 'published_at' => ['missing'],
            'expires_at' => ['missing'], 'donation_date' => ['missing'], 'notify_donors' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->any() && is_array($this->input('description_document'))) {
                try {
                    AnnouncementContent::plainText($this->input('description_document'), allowEmpty: true);
                } catch (ValidationException $error) {
                    $validator->errors()->add('description_document', $error->getMessage());
                }
            }
        }];
    }
}
