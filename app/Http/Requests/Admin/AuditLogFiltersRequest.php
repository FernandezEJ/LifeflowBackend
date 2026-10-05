<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AuditLogFiltersRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $month = $this->input('month');
        if (is_string($month) && strlen($month) <= 2 && ctype_digit($month)) {
            $this->merge(['month' => (int) $month]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'module' => ['sometimes', 'nullable', 'string', 'max:255'],
            'month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
