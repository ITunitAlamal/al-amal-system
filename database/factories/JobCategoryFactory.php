<?php

namespace Database\Factories;

use App\Models\JobCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobCategory> */
class JobCategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->uuid(), 'name_ar' => fake()->words(2, true),
            'is_active' => true, 'sort_order' => 0,
        ];
    }
}
