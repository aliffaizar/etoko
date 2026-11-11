<?php

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentTrackingEvent>
 */
class ShipmentTrackingEventFactory extends Factory
{
    /**
     * One courier scan.
     *
     * `description` is derived from the final `status` through a closure over
     * `$attributes`, so a caller who overrides only the status does not end up
     * with a row whose words describe a different scan (the rule BUG-08
     * bought).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'status' => ShipmentStatus::InTransit,
            'description' => fn (array $attributes): string => ShipmentStatus::from(
                $attributes['status'] instanceof ShipmentStatus
                    ? $attributes['status']->value
                    : (string) $attributes['status'],
            )->label(),
            'location' => fake()->randomElement(['Jakarta', 'Bandung', 'Surabaya', 'Semarang']),
            'source' => ShipmentTrackingEvent::SOURCE_COURIER,
            'event_at' => now(),
        ];
    }

    public function forShipment(Shipment $shipment): static
    {
        return $this->state(['shipment_id' => $shipment->getKey()]);
    }

    public function at(CarbonInterface $moment): static
    {
        return $this->state(['event_at' => $moment]);
    }

    public function withStatus(ShipmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function fromBranch(): static
    {
        return $this->state(['source' => ShipmentTrackingEvent::SOURCE_BRANCH]);
    }
}
