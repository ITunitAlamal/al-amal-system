<?php

namespace Database\Factories;

use App\Models\Guardian;
use App\Services\Orphans\RegistryIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Guardian> */
class GuardianFactory extends Factory
{
    public function definition(): array
    {
        $identity = fake()->unique()->numerify('#########');

        return [
            'name' => fake()->name(), 'national_id' => $identity,
            'national_id_hash' => RegistryIdentity::hash($identity),
            'phone' => fake()->unique()->numerify('059#######'),
            'password' => 'GuardianPassword123', 'is_active' => true,
        ];
    }
}
