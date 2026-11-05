<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    /**
     * Stock on hand with nothing reserved and no minimum set, which is what a
     * fresh row looks like after the first goods arrive.
     *
     * `branch_id` is denormalised from the warehouse, so `forWarehouse()` and
     * `inWarehouse()` keep the two in step rather than leaving a caller to
     * remember.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warehouse = Warehouse::factory();

        return [
            'warehouse_id' => $warehouse,
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 200),
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'max_quantity' => null,
            'shelf_location' => null,
        ];
    }

    /**
     * Put the row in a warehouse that already exists, carrying its branch over.
     */
    public function inWarehouse(Warehouse $warehouse): static
    {
        return $this->state([
            'warehouse_id' => $warehouse->getKey(),
            'branch_id' => $warehouse->branch_id,
        ]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(['product_variant_id' => $variant->getKey()]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }

    public function reserved(int $quantity): static
    {
        return $this->state(['reserved_quantity' => $quantity]);
    }

    public function minimum(int $quantity): static
    {
        return $this->state(['min_quantity' => $quantity]);
    }

    /**
     * On hand at or below its own minimum, which is what raises a low-stock
     * alert (P1-E4-T9).
     */
    public function low(): static
    {
        return $this->state(['quantity' => 2, 'min_quantity' => 5]);
    }

    public function empty(): static
    {
        return $this->state(['quantity' => 0]);
    }
}
