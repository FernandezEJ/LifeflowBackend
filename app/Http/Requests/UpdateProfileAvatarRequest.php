<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['profile_avatar' => ['required', 'string', Rule::in(['mascot_1', 'mascot_2', 'mascot_3', 'mascot_4', 'mascot_5'])]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['profile_avatar']) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        }];
    }
}
