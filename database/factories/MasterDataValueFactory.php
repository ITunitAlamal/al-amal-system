<?php

namespace Database\Factories;

use App\Models\MasterDataValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<MasterDataValue> */
class MasterDataValueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['type_id' => function (): int {
            $group = DB::table('master_data_groups')->insertGetId(['code' => fake()->unique()->uuid(), 'name_ar' => 'Test group', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('master_data_types')->insertGetId(['group_id' => $group, 'code' => fake()->unique()->uuid(), 'name_ar' => 'Test type', 'created_at' => now(), 'updated_at' => now()]);
        },
            'code' => fake()->unique()->uuid(), 'name_ar' => fake()->words(2, true),
            'is_active' => true, 'sort_order' => 0,
        ];
    }
}
