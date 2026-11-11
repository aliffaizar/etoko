<?php

namespace Database\Factories;

use App\Enums\ShippingRateScope;
use App\Models\Branch;
use App\Models\CourierService;
use App\Models\ShippingRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    /**
     * The catch-all rule: this service, any origin, any destination.
     *
     * `courier_service_id` is created rather than left null, because a rule
     * with no service at all applies to every courier in the shop and a test
     * that produced one by accident would make every quote agree.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'courier_service_id' => CourierService::factory(),
            'origin_branch_id' => null,
            'destination_region_id' => null,
            'destination_type' => ShippingRateScope::All,
            'destination_name' => null,
            'flat_price' => 15_000,
            'price_per_kg' => 5_000,
            'min_weight_gram' => 1_000,
            'is_active' => true,
        ];
    }

    public function forService(CourierService $service): static
    {
        return $this->state(['courier_service_id' => $service->getKey()]);
    }

    public function fromBranch(Branch $branch): static
    {
        return $this->state(['origin_branch_id' => $branch->getKey()]);
    }

    /**
     * A rule written for one destination, named the way a customer types it
     * (ADR-043).
     */
    public function toDestination(ShippingRateScope $scope, string $name): static
    {
        return $this->state([
            'destination_type' => $scope,
            'destination_name' => $name,
        ]);
    }

    public function priced(int $flat, int $perKilogram = 0, int $minimumWeightGram = 1_000): static
    {
        return $this->state([
            'flat_price' => $flat,
            'price_per_kg' => $perKilogram,
            'min_weight_gram' => $minimumWeightGram,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
