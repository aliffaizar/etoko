<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * An active hold for an order, expiring in fifteen minutes.
     *
     * The row on its own does **not** hold any stock: a hold is the row *and*
     * the matching `stocks.reserved_quantity`, written together by
     * `ReserveStock`. Tests about overselling must go through that action; this
     * factory is for tests about the row itself — the expiry sweep, the
     * listing, the status transitions.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warehouse = Warehouse::factory();

        return [
            'order_id' => Order::factory(),
            'cart_id' => null,
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => $warehouse,
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'quantity' => fake()->numberBetween(1, 5),
            'status' => ReservationStatus::Active,
            'expires_at' => now()->addMinutes(15),
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

    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->getKey(),
            'branch_id' => $order->branch_id,
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }

    /**
     * Past its moment, which is what `ExpireStockReservations` sweeps.
     */
    public function expired(): static
    {
        return $this->state([
            'status' => ReservationStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * A hold that never expires on its own — released when its order is
     * cancelled, committed when it is paid.
     */
    public function withoutExpiry(): static
    {
        return $this->state(['expires_at' => null]);
    }

    public function committed(): static
    {
        return $this->state(['status' => ReservationStatus::Committed]);
    }

    public function released(): static
    {
        return $this->state(['status' => ReservationStatus::Released]);
    }
}
