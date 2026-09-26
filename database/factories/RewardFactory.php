<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

// ========================================
// ISOLATED TEST INVENTORY
// No seeder calls this factory; real rewards remain empty until admin publishing.
// ========================================
class RewardFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'description' => fake()->sentence(),
            'points_cost' => 300, 'stock_quantity' => 2, 'active' => true];
    }
}
