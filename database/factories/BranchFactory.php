<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Province names, because Faker's `state()` is not localised for id_ID.
     *
     * @var array<int, string>
     */
    public const PROVINCES = [
        'DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'Jawa Timur', 'Banten',
        'DI Yogyakarta', 'Bali', 'Sumatera Utara', 'Sumatera Selatan',
        'Sulawesi Selatan', 'Kalimantan Timur',
    ];

    /**
     * @var array<int, string>
     */
    public const DISTRICTS = [
        'Menteng', 'Cilandak', 'Kebayoran Baru', 'Tebet', 'Coblong',
        'Lowokwaru', 'Gubeng', 'Denpasar Barat', 'Medan Baru', 'Panakkukang',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->city();
        $name = "Cabang {$city}";

        return [
            'code' => mb_strtoupper(fake()->unique()->bothify('???-##')),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->companyEmail(),
            'province_name' => fake()->randomElement(self::PROVINCES),
            'city_name' => $city,
            'district_name' => fake()->randomElement(self::DISTRICTS),
            'postal_code' => fake()->postcode(),
            'address_line' => fake()->streetAddress(),
            'latitude' => fake()->latitude(-11, 6),
            'longitude' => fake()->longitude(95, 141),
            'status' => BranchStatus::Active,
            'supports_delivery' => true,
            'supports_pickup' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => BranchStatus::Inactive]);
    }

    public function underMaintenance(): static
    {
        return $this->state(['status' => BranchStatus::Maintenance]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function deliveryOnly(): static
    {
        return $this->state(['supports_delivery' => true, 'supports_pickup' => false]);
    }

    public function pickupOnly(): static
    {
        return $this->state(['supports_delivery' => false, 'supports_pickup' => true]);
    }
}
