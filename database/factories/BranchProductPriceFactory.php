<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\ProductVariant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchProductPrice>
 */
class BranchProductPriceFactory extends Factory
{
    /**
     * An active price with no schedule, which is the common case: a branch
     * that simply charges differently.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'price' => fake()->numberBetween(15, 2500) * 1000,
            'compare_at_price' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function at(int $price): static
    {
        return $this->state(['price' => $price]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A price that has not started yet.
     */
    public function startingAt(DateTimeInterface $moment): static
    {
        return $this->state(['starts_at' => $moment]);
    }

    /**
     * A price that stops applying after the given moment.
     */
    public function endingAt(DateTimeInterface $moment): static
    {
        return $this->state(['ends_at' => $moment]);
    }

    /**
     * A price that applies only within the given window.
     */
    public function between(DateTimeInterface $start, DateTimeInterface $end): static
    {
        return $this->state(['starts_at' => $start, 'ends_at' => $end]);
    }

    public function discountedFrom(int $compareAtPrice): static
    {
        return $this->state(['compare_at_price' => $compareAtPrice]);
    }
}
