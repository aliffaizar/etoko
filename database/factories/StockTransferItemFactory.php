<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity_requested' => fake()->numberBetween(1, 10),
            'quantity_approved' => null,
            'quantity_shipped' => null,
            'quantity_received' => null,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(['product_variant_id' => $variant->getKey()]);
    }

    public function requested(int $quantity): static
    {
        return $this->state(['quantity_requested' => $quantity]);
    }

    /**
     * Approved at the requested figure unless told otherwise.
     */
    public function approved(?int $quantity = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_approved' => $quantity ?? $attributes['quantity_requested'],
        ]);
    }

    /**
     * Shipped at the approved figure unless told otherwise.
     *
     * Only the line: the ledger rows that shipping writes are not invented
     * here. A test that needs them ships through `ShipStockTransfer`.
     */
    public function shipped(?int $quantity = null): static
    {
        return $this->state(function (array $attributes) use ($quantity): array {
            $approved = $attributes['quantity_approved'] ?? $attributes['quantity_requested'];

            return [
                'quantity_approved' => $approved,
                'quantity_shipped' => $quantity ?? $approved,
            ];
        });
    }
}
