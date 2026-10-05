<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:grocery,medicine,fitness,other'],
            'amount_mode' => ['sometimes', 'in:fixed,custom'],
            'voucher_value' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'points_cost' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'expected_stock' => [Rule::requiredIf($this->route('reward') !== null), 'integer', 'min:0', 'max:2147483647'],
            'status' => ['required', 'in:active,inactive'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png', 'max:5120'],
        ];
    }
}
