<?php

namespace Database\Factories;

use App\Models\Guardian;
use App\Models\GuardianApplication;
use App\Services\Orphans\RegistryIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GuardianApplication> */
class GuardianApplicationFactory extends Factory
{
    public function definition(): array
    {
        $deceased = fake()->unique()->numerify('#########');

        return [
            'public_id' => (string) Str::ulid(), 'guardian_id' => Guardian::factory(),
            'deceased_national_id' => $deceased, 'deceased_id_hash' => RegistryIdentity::hash($deceased),
            'relationship' => 'وصي على أطفال المتوفى', 'status' => 'awaiting_api', 'verification_status' => 'unavailable',
        ];
    }
}
