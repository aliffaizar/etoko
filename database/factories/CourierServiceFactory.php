<?php

namespace Database\Factories;

use App\Models\Courier;
use App\Models\CourierService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierService>
 */
class CourierServiceFactory extends Factory
{
    /**
     * A regular service with a two-to-three-day promise, which is what every
     * Indonesian courier's cheapest tier looks like.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'courier_id' => Courier::factory(),
            'code' => 'REG'.fake()->unique()->numerify('###'),
            'name' => 'Reguler',
            'description' => 'Pengiriman standar, tanpa jaminan hari tiba.',
            'etd_min_day' => 2,
            'etd_max_day' => 3,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forCourier(Courier $courier): static
    {
        return $this->state(['courier_id' => $courier->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A next-day service, for tests about ordering quotes by speed or price.
     */
    public function express(): static
    {
        return $this->state([
            'code' => 'YES'.fake()->unique()->numerify('###'),
            'name' => 'Express',
            'description' => 'Sampai keesokan hari untuk kota besar.',
            'etd_min_day' => 1,
            'etd_max_day' => 1,
            'sort_order' => 1,
        ]);
    }

    /**
     * A service that makes no promise about when it lands.
     */
    public function withoutEstimate(): static
    {
        return $this->state(['etd_min_day' => null, 'etd_max_day' => null]);
    }
}
