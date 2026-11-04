<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory untuk master data instansi.
 *
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'acronym' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->sentence(),
            'logo_url' => null,
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'sla_days' => fake()->numberBetween(1, 7),
            'is_active' => true,
        ];
    }

    /**
     * Instansi yang sudah dinonaktifkan.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
