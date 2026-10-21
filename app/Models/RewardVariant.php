<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardVariant extends Model
{
    public const SIZES = ['S', 'M', 'L', 'XL', '2XL'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'stock_quantity' => 'integer'];
    }
}
