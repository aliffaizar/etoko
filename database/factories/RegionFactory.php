<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'type' => Region::TYPE_PROVINCE,
            'name' => fake()->randomElement(BranchFactory::PROVINCES),
            'code' => fake()->unique()->numerify('##'),
            'postal_code' => null,
        ];
    }

    public function city(): static
    {
        return $this->state(['type' => Region::TYPE_CITY, 'name' => fake()->city()]);
    }

    public function district(): static
    {
        return $this->state(['type' => Region::TYPE_DISTRICT, 'name' => fake()->randomElement(BranchFactory::DISTRICTS)]);
    }
}
