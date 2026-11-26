<?php

namespace Database\Factories;

use App\Models\JobGrade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<JobGrade> */
class JobGradeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['career_scale_id' => fn () => DB::table('career_scales')->insertGetId(['code' => fake()->unique()->uuid(), 'name_ar' => fake()->words(2, true), 'created_at' => now(), 'updated_at' => now()]),
            'code' => fake()->unique()->uuid(), 'name_ar' => fake()->words(2, true),
            'is_active' => true, 'sort_order' => 0,
        ];
    }
}
