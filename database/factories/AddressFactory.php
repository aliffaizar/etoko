<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Rumah', 'Kantor', 'Lainnya']),
            'recipient_name' => fake()->name(),
            'phone' => '08'.fake()->numerify('#########'),
            'province_id' => null,
            'city_id' => null,
            'district_id' => null,
            'province_name' => fake()->randomElement(['DKI Jakarta', 'Jawa Barat', 'Jawa Timur']),
            'city_name' => fake()->randomElement(['Jakarta Selatan', 'Bandung', 'Surabaya']),
            'district_name' => fake()->randomElement(['Kebayoran Baru', 'Coblong', 'Gubeng']),
            'postal_code' => (string) fake()->numberBetween(10000, 99999),
            'address_line' => fake()->streetAddress(),
            'latitude' => null,
            'longitude' => null,
            'is_default' => false,
            'notes' => null,
        ];
    }

    public function asDefault(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }
}
