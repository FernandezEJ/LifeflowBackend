<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SystemReportRequest extends FormRequest
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
            'year' => ['sometimes', 'integer', 'between:2000,'.(now('Asia/Manila')->year + 1)],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ];
    }
}
