<?php

namespace Database\Factories;

use App\Models\OrphanChild;
use App\Services\Orphans\RegistryIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrphanChild> */
class OrphanChildFactory extends Factory
{
    public function definition(): array
    {
        $identity = fake()->unique()->numerify('#########');

        return [
            'public_id' => (string) Str::ulid(), 'source' => 'test-registry', 'source_child_id' => fake()->unique()->uuid(),
            'national_id' => $identity, 'national_id_hash' => RegistryIdentity::hash($identity),
            'full_name' => fake()->name(), 'date_of_birth' => '2015-05-10', 'status' => 'active',
        ];
    }
}
