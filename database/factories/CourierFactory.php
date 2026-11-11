<?php

namespace Database\Factories;

use App\Enums\ShippingDriver;
use App\Models\Courier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Courier>
 */
class CourierFactory extends Factory
{
    /**
     * An active courier served by the manual rate table — the one most tests
     * want, because it is the driver the whole of `P2-E2-T2` is about.
     *
     * `code` is derived from the final `name` through a closure over
     * `$attributes` rather than from a local variable, so a caller who
     * overrides only the name still gets a matching code (the rule BUG-08
     * bought).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['JNE', 'J&T Express', 'SiCepat', 'AnterAja', 'Ninja Xpress', 'Pos Indonesia']),
            'code' => fn (array $attributes): string => Str::slug((string) $attributes['name']).'-'.fake()->unique()->numerify('####'),
            'logo_path' => null,
            'driver' => ShippingDriver::Manual,
            'is_active' => true,
            'sort_order' => 0,
            'config' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A courier served by an aggregator (`P2-E2-T6`).
     *
     * The provider's own code is set explicitly, because the mapping between
     * our `code` and theirs is the thing the driver depends on and a test that
     * let it default would not be testing it.
     */
    public function biteship(string $providerCode = 'jne'): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => ShippingDriver::Biteship,
            'config' => [
                ...(is_array($attributes['config'] ?? null) ? $attributes['config'] : []),
                'biteship' => ['courier_code' => $providerCode],
            ],
        ]);
    }

    public function rajaOngkir(): static
    {
        return $this->state(['driver' => ShippingDriver::RajaOngkir]);
    }

    /**
     * A courier that publishes its own tracking page.
     */
    public function withTrackingUrl(string $template = 'https://example.test/cek/{tracking_number}'): static
    {
        return $this->state(fn (array $attributes): array => [
            'config' => [
                ...(is_array($attributes['config'] ?? null) ? $attributes['config'] : []),
                'tracking_url' => $template,
            ],
        ]);
    }
}
