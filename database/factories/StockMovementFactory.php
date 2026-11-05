<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Goods arriving, with the before/after figures agreeing with the change.
     *
     * Tests that need a ledger consistent with `stocks` should post through
     * `StockLedger` instead; this factory is for listing and history screens,
     * which only read.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $before = fake()->numberBetween(0, 100);
        $change = fake()->numberBetween(1, 50);

        return [
            'warehouse_id' => Warehouse::factory(),
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'product_variant_id' => ProductVariant::factory(),
            'type' => StockMovementType::EntryIn,
            'quantity_change' => $change,
            'quantity_before' => $before,
            'quantity_after' => $before + $change,
            'reference_type' => null,
            'reference_id' => null,
            'unit_cost' => null,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

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

    /**
     * A movement of the given signed size, with before/after kept consistent.
     */
    public function change(StockMovementType $type, int $quantityChange, int $quantityBefore = 0): static
    {
        return $this->state([
            'type' => $type,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityBefore + $quantityChange,
        ]);
    }

    public function referencing(Model $reference): static
    {
        return $this->state([
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
        ]);
    }
}
