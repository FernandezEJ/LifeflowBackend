<?php

namespace App\Http\Requests\Admin;

use App\Models\Reward;
use App\Models\RewardVariant;
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
        $existing = $this->route('reward') ? Reward::find($this->route('reward')) : null;
        $categories = Reward::CATEGORIES;
        if ($existing && in_array($existing->category, Reward::LEGACY_CATEGORIES, true)) {
            $categories[] = $existing->category;
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in($categories)],
            'amount_mode' => ['sometimes', 'in:fixed,custom'],
            'voucher_value' => [Rule::excludeIf(in_array($this->input('category'), ['merchandise', 'apparel'], true)), Rule::requiredIf($this->input('category') === 'vouchers'), 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'points_cost' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'stock_quantity' => [Rule::excludeIf($this->input('category') === 'apparel'), 'required', 'integer', 'min:0', 'max:2147483647'],
            'expected_stock' => [Rule::requiredIf($this->route('reward') !== null), 'integer', 'min:0', 'max:2147483647'],
            'expected_inventory_version' => [Rule::requiredIf($existing && ($existing->category === 'apparel' || $this->input('category') === 'apparel')), 'integer', 'min:0'],
            'variants' => [Rule::excludeIf($this->input('category') !== 'apparel'), 'required', 'array', 'min:1', 'max:5'],
            'variants.*' => ['required', 'array:size,stock_quantity'],
            'variants.*.size' => ['required', Rule::in(RewardVariant::SIZES), 'distinct'],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0', 'max:429496729'],
            'status' => ['required', 'in:active,inactive'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png', 'max:5120'],
        ];
    }
}
