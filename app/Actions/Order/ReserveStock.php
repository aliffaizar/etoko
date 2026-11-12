<?php

namespace App\Actions\Order;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Services\Inventory\StockAllocation;
use App\Services\Inventory\StockAllocator;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Holds the stock an unpaid order has claimed (P1-E7-T2, BR-6).
 *
 * This is the step that makes overselling impossible. Nothing leaves the
 * shelf: on-hand is untouched and `stocks.reserved_quantity` goes up, which
 * lowers what everyone else may buy. `StockLedger` holds the row lock while it
 * checks and writes, so of two checkouts racing for the last unit exactly one
 * gets it and the other is refused — the refusal comes from inside the lock,
 * not from a check that could already be stale.
 *
 * Every hold also gets a `stock_reservations` row, which is what makes it
 * *givable back*: `reserved_quantity` alone is an anonymous number that no
 * later request could attribute to an abandoned basket.
 *
 * Lines are processed in `product_variant_id` order and each line's warehouses
 * in a fixed order (see `StockAllocator`), so two checkouts after the same
 * goods always take their locks in the same direction instead of deadlocking.
 */
final class ReserveStock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockAllocator $allocator,
    ) {}

    /**
     * Hold every line of an order, or hold nothing at all.
     *
     * One transaction: a line that cannot be covered rolls back the holds
     * already taken for the lines before it, because a half-reserved order is
     * stock held for an order that will never exist.
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lines
     * @param  Carbon|null  $expiresAt  Null means the hold never expires on its own.
     * @return Collection<int, StockReservation>
     *
     * @throws InsufficientStockException When the order's branch cannot cover a line.
     */
    public function handle(Order $order, array $lines, ?Carbon $expiresAt = null): Collection
    {
        if ($lines === []) {
            throw new LogicException('An order with no lines has nothing to reserve.');
        }

        $expiresAt ??= self::defaultExpiry();

        return DB::transaction(function () use ($order, $lines, $expiresAt): Collection {
            $order->loadMissing('branch');
            $reservations = new Collection;

            foreach ($this->inLockOrder($lines) as $line) {
                foreach ($this->allocator->allocate($order->branch, $line['variant'], $line['quantity']) as $allocation) {
                    $reservations->push($this->hold($order, $line['variant'], $allocation, $expiresAt));
                }
            }

            return $reservations;
        });
    }

    /**
     * How long a hold lasts by default, from `config/shop.php`.
     */
    public static function defaultExpiry(): Carbon
    {
        return Carbon::now()->addMinutes((int) config('shop.reservation_minutes', 1440));
    }

    /**
     * Take one hold: the figure and the row that explains it, together.
     */
    private function hold(
        Order $order,
        ProductVariant $variant,
        StockAllocation $allocation,
        ?Carbon $expiresAt,
    ): StockReservation {
        $reservation = StockReservation::create([
            'order_id' => $order->getKey(),
            'cart_id' => null,
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $allocation->warehouse->getKey(),
            'branch_id' => $allocation->warehouse->branch_id,
            'quantity' => $allocation->quantity,
            'status' => ReservationStatus::Active,
            'expires_at' => $expiresAt,
        ]);

        $this->ledger->apply(
            warehouse: $allocation->warehouse,
            variant: $variant,
            type: StockMovementType::SaleReservation,
            quantityChange: 0,
            reference: $order,
            notes: "Ditahan untuk pesanan {$order->order_number}.",
            reservedChange: $allocation->quantity,
        );

        return $reservation;
    }

    /**
     * The lines sorted by variant id — the order in which locks are taken.
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lines
     * @return array<int, array{variant: ProductVariant, quantity: int}>
     */
    private function inLockOrder(array $lines): array
    {
        usort(
            $lines,
            fn (array $a, array $b): int => $a['variant']->getKey() <=> $b['variant']->getKey(),
        );

        return $lines;
    }
}
