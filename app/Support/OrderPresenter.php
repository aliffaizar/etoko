<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;

/**
 * How orders reach the screens (P1-E7-T6, T8).
 *
 * Four screens read an order — the customer's history, the customer's detail
 * page, the panel listing and the panel detail — and three of them show the
 * same figures. Keeping the shapes together is what stops the same status from
 * being labelled two ways, or a total being recomputed differently on one
 * screen than another.
 *
 * Everything here reads **snapshots** off the order and its lines, never the
 * catalogue (ADR-007): a product renamed last week must not rename itself on a
 * receipt from last month. The only live figures are the capability flags,
 * which are about what may be done now.
 *
 * The shapes mirror the TypeScript types in `resources/js/types/order.ts`.
 */
final class OrderPresenter
{
    /**
     * One row of a listing — the customer's history and the panel's queue.
     *
     * `items_sum_quantity` and the branch name are expected to be loaded by
     * the caller (`withSum('items', 'quantity')`, `with('branch:id,name')`), so
     * drawing a page costs no query per row.
     *
     * @return array<string, mixed>
     */
    public static function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'payment_status' => $order->payment_status->value,
            'payment_status_label' => $order->payment_status->label(),
            'fulfillment_type' => $order->fulfillment_type->value,
            'fulfillment_label' => $order->fulfillment_type->label(),
            'channel' => $order->channel->value,
            'customer_name' => $order->customer_name,
            // `orders.branch_id` is NOT NULL (BR-5), so a loaded relation is
            // always there; null here means the caller did not load it.
            'branch' => $order->relationLoaded('branch')
                ? ['id' => $order->branch->id, 'name' => $order->branch->name]
                : null,
            'item_count' => $order->totalQuantity(),
            'grand_total' => $order->grand_total,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * The whole order, as a detail screen reads it.
     *
     * @param  array<string, bool>  $can  Capability flags the caller worked out from the policy.
     * @return array<string, mixed>
     */
    public static function detail(Order $order, array $can = []): array
    {
        return [
            ...self::row($order),
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'customer_id' => $order->user_id,
            'shipping' => self::shipping($order),
            'totals' => [
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'shipping_cost' => $order->shipping_cost,
                'tax_total' => $order->tax_total,
                'grand_total' => $order->grand_total,
                'total_weight_gram' => $order->total_weight_gram,
            ],
            'voucher_code' => $order->voucher_code,
            'customer_note' => $order->customer_note,
            'internal_note' => $order->internal_note,
            'cancel_reason' => $order->cancel_reason,
            'moments' => [
                'placed_at' => $order->placed_at?->toIso8601String(),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'processed_at' => $order->processed_at?->toIso8601String(),
                'ready_at' => $order->ready_at?->toIso8601String(),
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            ],
            'items' => $order->items->map(fn (OrderItem $item): array => self::item($item))->values()->all(),
            'timeline' => $order->statusHistories
                ->map(fn (OrderStatusHistory $history): array => self::timelineEntry($history))
                ->values()
                ->all(),
            'can' => $can,
        ];
    }

    /**
     * One line of an order — every field a snapshot taken at checkout.
     *
     * `product_slug` is the one live reading, and it is deliberately nullable:
     * it only exists to offer a link back to the product page, and a product
     * since deleted simply has no link. The *name* still comes from the
     * snapshot either way.
     *
     * @return array<string, mixed>
     */
    public static function item(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'variant_name' => $item->variant_name,
            'display_name' => $item->displayName(),
            'image_url' => $item->image_path === null ? null : asset('storage/'.$item->image_path),
            'product_slug' => $item->relationLoaded('product') ? $item->product?->slug : null,
            'unit_price' => $item->unit_price,
            'quantity' => $item->quantity,
            'discount_amount' => $item->discount_amount,
            'subtotal' => $item->subtotal,
            'weight_gram' => $item->weight_gram,
            'refunded_quantity' => $item->refunded_quantity,
        ];
    }

    /**
     * One step of the timeline.
     *
     * A row whose `from_status` equals its `to_status` is not a status change
     * at all — it is a note on the order, which is how a branch reassignment
     * records itself (P1-E7-T10). The screen reads `is_note` rather than
     * comparing the two itself.
     *
     * @return array<string, mixed>
     */
    public static function timelineEntry(OrderStatusHistory $history): array
    {
        return [
            'id' => $history->id,
            'from_status' => $history->from_status?->value,
            'from_label' => $history->from_status?->label(),
            'to_status' => $history->to_status->value,
            'to_label' => $history->to_status->label(),
            'is_note' => $history->from_status === $history->to_status,
            'note' => $history->note,
            'changed_by' => $history->relationLoaded('changedBy') ? $history->changedBy?->name : null,
            'created_at' => $history->created_at?->toIso8601String(),
        ];
    }

    /**
     * The delivery address snapshot, or null for a pickup order.
     *
     * @return array<string, mixed>|null
     */
    public static function shipping(Order $order): ?array
    {
        if (! $order->fulfillment_type->requiresShipping()) {
            return null;
        }

        return [
            'recipient' => $order->shipping_recipient,
            'phone' => $order->shipping_phone,
            'address_line' => $order->shipping_address_line,
            'district_name' => $order->shipping_district_name,
            'city_name' => $order->shipping_city_name,
            'province_name' => $order->shipping_province_name,
            'postal_code' => $order->shipping_postal_code,
        ];
    }

    /**
     * The order's invoice, or null while it has none to give (P2-E6-T2).
     *
     * Offered as soon as the order has been paid for, even before the
     * listener has issued it: the download issues it on the way, so the
     * screen never waits on the queue. `number` is null in that window.
     *
     * @return array{number: string|null, issued_at: string|null}|null
     */
    public static function invoice(Order $order): ?array
    {
        if ($order->invoiceBlockedReason() !== null) {
            return null;
        }

        return [
            'number' => $order->invoice?->invoice_number,
            'issued_at' => $order->invoice?->issued_at->toIso8601String(),
        ];
    }

    /**
     * The stock an order is still holding, warehouse by warehouse (P1-E7-T8).
     *
     * Shown on the panel's detail screen so a branch can see *why* units are
     * off the shelf, and see them go when the order is paid for or cancelled.
     *
     * @param  Collection<int, StockReservation>  $reservations
     * @return array<int, array<string, mixed>>
     */
    public static function reservations(Collection $reservations): array
    {
        return $reservations->map(fn (StockReservation $reservation): array => [
            'id' => $reservation->id,
            'sku' => $reservation->relationLoaded('variant') ? $reservation->variant->sku : null,
            'warehouse_name' => $reservation->relationLoaded('warehouse') ? $reservation->warehouse->name : null,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'expires_at' => $reservation->expires_at?->toIso8601String(),
        ])->values()->all();
    }
}
