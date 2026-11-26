<?php

namespace Database\Factories;

use App\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Guardian> */
class GuardianFactory extends Factory
{
    public function definition(): array
    {
        $identity = fake()->unique()->numerify('#########');

        return [
            'name' => fake()->name(), 'national_id' => $identity,
            'national_id_hash' => hash_hmac('sha256', $identity, (string) config('app.key')),
            'phone' => fake()->unique()->numerify('059#######'),
            'password' => 'GuardianPassword123', 'is_active' => true,
        ];
    }
}
