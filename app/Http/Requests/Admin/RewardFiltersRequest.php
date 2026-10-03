<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RewardFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:100000'],
            'status' => ['sometimes', 'in:all,active,inactive,deleted,available,redeemed,expired'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:'.(now(config('app.calendar_timezone'))->year + 1)],
            'month' => ['sometimes', 'integer', 'between:1,12']];
    }
}
