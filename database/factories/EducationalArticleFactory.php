<?php

namespace Database\Factories;

use App\Models\EducationalArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EducationalArticle>
 */
class EducationalArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => '', 'category' => null, 'short_description' => '', 'description' => '', 'status' => 'draft',
        ];
    }
}
