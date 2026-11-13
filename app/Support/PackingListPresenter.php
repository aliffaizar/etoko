<?php

namespace App\Support;

use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Stock;
use App\Models\StockReservation;
use Illuminate\Support\Collection;

/**
 * An order as the warehouse reads it: what to take, from which warehouse and
 * which shelf (P3-E6-T4, ADR-062).
 *
 * Where the goods come from is not decided here. `StockAllocator` decided it at
 * checkout (default warehouse first, then the rest, ADR-033) and the decision
 * is written on the order's `stock_reservations`, which `CommitReservation`
 * turned into the sale when the order was paid. This only reads those rows
 * back, grouped per warehouse, so the picking list and the ledger can never
 * disagree about which shelf a unit left.
 *
 * The shelf location is looked up on the `stocks` row of each (warehouse,
 * variant) pair in one query for the whole page.
 */
final class PackingListPresenter
{
    /** Shown for a line with no committed hold, which a paid order should never have. */
    public const UNKNOWN_WAREHOUSE = 'Gudang belum tercatat';

    /**
     * @param  Collection<int, Order>  $orders  With `items` and `stockReservations.warehouse` loaded.
     * @return array<int, array<string, mixed>>
     */
    public static function orders(Collection $orders): array
    {
        $shelves = self::shelvesFor($orders);

        return $orders->map(fn (Order $order): array => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'fulfillment_type' => $order->fulfillment_type->value,
            'fulfillment_label' => $order->fulfillment_type->label(),
            'customer_name' => $order->customer_name,
            'customer_note' => $order->customer_note,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'processed_at' => $order->processed_at?->toIso8601String(),
            'unit_count' => (int) $order->items->sum('quantity'),
            'picks' => self::picks($order, $shelves),
        ])->values()->all();
    }

    /**
     * The order's lines, grouped by the warehouse each unit comes out of.
     *
     * @param  array<string, string|null>  $shelves
     * @return array<int, array{warehouse: string, lines: array<int, array{sku: string, product_name: string, variant_name: string|null, quantity: int, shelf_location: string|null}>}>
     */
    private static function picks(Order $order, array $shelves): array
    {
        $items = $order->items->keyBy('product_variant_id');
        $groups = [];
        $covered = [];

        foreach (self::committed($order) as $reservation) {
            $item = $items->get($reservation->product_variant_id);

            if (! $item instanceof OrderItem) {
                continue;
            }

            $name = $reservation->warehouse->name;
            $groups[$name][] = self::line(
                $item,
                $reservation->quantity,
                $shelves[self::shelfKey($reservation->warehouse_id, $reservation->product_variant_id)] ?? null,
            );
            $covered[$reservation->product_variant_id] = ($covered[$reservation->product_variant_id] ?? 0) + $reservation->quantity;
        }

        foreach ($order->items as $item) {
            $missing = $item->quantity - ($covered[$item->product_variant_id] ?? 0);

            if ($missing > 0) {
                $groups[self::UNKNOWN_WAREHOUSE][] = self::line($item, $missing, null);
            }
        }

        return collect($groups)
            ->map(fn (array $lines, string $warehouse): array => ['warehouse' => $warehouse, 'lines' => $lines])
            ->values()
            ->all();
    }

    /**
     * @return array{sku: string, product_name: string, variant_name: string|null, quantity: int, shelf_location: string|null}
     */
    private static function line(OrderItem $item, int $quantity, ?string $shelf): array
    {
        return [
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'variant_name' => $item->variant_name,
            'quantity' => $quantity,
            'shelf_location' => $shelf,
        ];
    }

    /**
     * @return Collection<int, StockReservation>
     */
    private static function committed(Order $order): Collection
    {
        return $order->stockReservations
            ->filter(fn (StockReservation $reservation): bool => $reservation->status === ReservationStatus::Committed)
            ->sortBy(fn (StockReservation $reservation): array => [
                $reservation->warehouse->is_default ? 0 : 1,
                $reservation->warehouse_id,
                $reservation->product_variant_id,
            ])
            ->values()
            ->toBase();
    }

    /**
     * Shelf locations for every (warehouse, variant) pair on the page.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, string|null>
     */
    private static function shelvesFor(Collection $orders): array
    {
        $reservations = $orders->flatMap(fn (Order $order): Collection => self::committed($order));

        if ($reservations->isEmpty()) {
            return [];
        }

        return Stock::acrossBranches()
            ->whereIn('warehouse_id', $reservations->pluck('warehouse_id')->unique()->values())
            ->whereIn('product_variant_id', $reservations->pluck('product_variant_id')->unique()->values())
            ->get(['warehouse_id', 'product_variant_id', 'shelf_location'])
            ->mapWithKeys(fn (Stock $stock): array => [
                self::shelfKey($stock->warehouse_id, $stock->product_variant_id) => $stock->shelf_location,
            ])
            ->all();
    }

    private static function shelfKey(int $warehouseId, int $variantId): string
    {
        return "{$warehouseId}:{$variantId}";
    }
}
