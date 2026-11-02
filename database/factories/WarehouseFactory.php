<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => mb_strtoupper(fake()->unique()->bothify('WH-???')),
            'name' => 'Gudang '.fake()->word(),
            'address' => fake()->streetAddress(),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true, 'code' => 'WH-01', 'name' => 'Gudang Utama']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
