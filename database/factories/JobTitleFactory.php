<?php

namespace Database\Factories;

use App\Models\JobCategory;
use App\Models\JobTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobTitle> */
class JobTitleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['job_category_id' => JobCategory::factory(),
            'code' => fake()->unique()->uuid(), 'name_ar' => fake()->words(2, true),
            'is_active' => true, 'sort_order' => 0,
        ];
    }
}
