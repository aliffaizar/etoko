<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\ReturnItem;

/**
 * How returns reach the screens (P2-E4-T2, T3).
 *
 * Four screens read a return — the customer's order page, the customer's
 * request form, the panel's return queue and the panel's return detail — and
 * they must agree about what a status is called, how many units are left to
 * send back, and what a line was sold for. Keeping the shapes here is what
 * stops that drifting, the same reason `OrderPresenter` and `PaymentPresenter`
 * exist.
 *
 * Two rules it follows throughout:
 *
 * - **Nothing descriptive is read from the catalogue.** A line's name, SKU and
 *   price come from `order_items`, which already holds the snapshot taken at
 *   checkout (ADR-007), through the `orderItem` relation. A product renamed
 *   last week must not rename itself on a return document from last month.
 * - **The customer's shape is not the panel's.** `forCustomer()` carries no
 *   internal note, no approver and no restock decision; a customer is told
 *   what the branch decided, not how the branch files it. The panel's
 *   `detail()` carries everything.
 *
 * The shapes mirror the TypeScript types in `resources/js/types/return.ts`.
 */
final class ReturnPresenter
{
    /**
     * One row of the panel's return queue.
     *
     * The order number, the customer name and the units are expected to be
     * loaded by the caller (`with('order:…')`, `withSum('items', 'quantity')`),
     * so drawing a page costs no query per row.
     *
     * @return array<string, mixed>
     */
    public static function row(OrderReturn $return): array
    {
        return [
            'id' => $return->id,
            'code' => $return->code,
            'type' => $return->type->value,
            'type_label' => $return->type->label(),
            'reason' => $return->reason->value,
            'reason_label' => $return->reason->label(),
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            'item_count' => $return->totalQuantity(),
            'photo_count' => count($return->photo_paths ?? []),
            'requested_at' => $return->requested_at?->toIso8601String(),
            'approved_at' => $return->approved_at?->toIso8601String(),
            'received_at' => $return->received_at?->toIso8601String(),
            'completed_at' => $return->completed_at?->toIso8601String(),
            'created_at' => $return->created_at?->toIso8601String(),
            'order' => $return->relationLoaded('order')
                ? [
                    'id' => $return->order->id,
                    'order_number' => $return->order->order_number,
                    'customer_name' => $return->order->customer_name,
                    'status' => $return->order->status->value,
                    'status_label' => $return->order->status->label(),
                    'payment_status' => $return->order->payment_status->value,
                    'payment_status_label' => $return->order->payment_status->label(),
                    'grand_total' => $return->order->grand_total,
                ]
                : null,
            'branch' => $return->relationLoaded('branch')
                ? ['id' => $return->branch->id, 'name' => $return->branch->name]
                : null,
        ];
    }

    /**
     * One return, as the panel's detail screen reads it.
     *
     * `goods_value` and `refunded_amount` are both carried because the
     * difference between them is the number a staff member is about to act on:
     * what the returned goods were sold for, and what has actually gone back.
     *
     * @param  array<string, bool>  $can  Capability flags the caller worked out from the policy.
     * @param  array<string, string|null>  $blocked  Why each action is unavailable, for the screen to say so.
     * @return array<string, mixed>
     */
    public static function detail(OrderReturn $return, array $can = [], array $blocked = []): array
    {
        return [
            ...self::row($return),
            'description' => $return->description,
            'photo_urls' => $return->photoUrls(),
            'rejection_reason' => $return->rejection_reason,
            'notes' => $return->notes,
            'goods_value' => $return->goodsValue(),
            'refunded_amount' => $return->refundedAmount(),
            'requested_by' => $return->relationLoaded('user') ? $return->user?->name : null,
            'approved_by' => $return->relationLoaded('approvedBy') ? $return->approvedBy?->name : null,
            'received_by' => $return->relationLoaded('receivedBy') ? $return->receivedBy?->name : null,
            'items' => $return->items->map(fn (ReturnItem $item): array => self::item($item))->values()->all(),
            'refunds' => $return->relationLoaded('refunds')
                ? $return->refunds->map(fn (Refund $refund): array => RefundPresenter::row($refund))->values()->all()
                : [],
            'can' => $can,
            'blocked' => $blocked,
        ];
    }

    /**
     * The return as the customer's own order page reads it (P2-E4-T2).
     *
     * Deliberately narrower than the panel's shape: no internal note, no
     * approver, no restock decision. What it does carry is what the customer
     * needs in order to *act* — where the request stands, what was rejected
     * and why, and whether they may still withdraw it.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(OrderReturn $return): array
    {
        return [
            'id' => $return->id,
            'code' => $return->code,
            'type' => $return->type->value,
            'type_label' => $return->type->label(),
            'reason' => $return->reason->value,
            'reason_label' => $return->reason->label(),
            'description' => $return->description,
            'photo_urls' => $return->photoUrls(),
            'status' => $return->status->value,
            'status_label' => $return->status->label(),
            // Said plainly, because a customer whose request was turned down
            // needs to know what to do differently — and the only sentences
            // written into `rejection_reason` are ones meant to be read.
            'rejection_reason' => $return->rejection_reason,
            'item_count' => $return->totalQuantity(),
            'items' => $return->items->map(fn (ReturnItem $item): array => [
                'id' => $item->id,
                'display_name' => $item->relationLoaded('orderItem')
                    ? $item->orderItem->displayName()
                    : null,
                'quantity' => $item->quantity,
            ])->values()->all(),
            'can_cancel' => $return->customerCancellationBlockedReason() === null,
            'refunded_amount' => $return->refundedAmount(),
            'requested_at' => $return->requested_at?->toIso8601String(),
            'approved_at' => $return->approved_at?->toIso8601String(),
            'received_at' => $return->received_at?->toIso8601String(),
            'completed_at' => $return->completed_at?->toIso8601String(),
        ];
    }

    /**
     * One line of a return, described through the order line it came from.
     *
     * @return array<string, mixed>
     */
    public static function item(ReturnItem $item): array
    {
        $line = $item->relationLoaded('orderItem') ? $item->orderItem : null;

        return [
            'id' => $item->id,
            'order_item_id' => $item->order_item_id,
            'sku' => $line?->sku,
            'display_name' => $line?->displayName(),
            'image_url' => $line === null || $line->image_path === null
                ? null
                : asset('storage/'.$line->image_path),
            'ordered_quantity' => $line === null ? 0 : $line->quantity,
            'quantity' => $item->quantity,
            'unit_value' => $item->unitValue(),
            'refundable_value' => $item->refundableValue(),
            'condition' => $item->condition->value,
            'condition_label' => $item->condition->label(),
            'restock' => $item->restock,
            'restocked_at' => $item->restocked_at?->toIso8601String(),
            'restock_warehouse' => $item->relationLoaded('restockWarehouse')
                ? $item->restockWarehouse?->name
                : null,
            // Whether this line could go back on the shelf at all. A variant
            // that has been deleted from the catalogue has nowhere to go, and
            // the screen says so rather than offering a tick box that will be
            // silently overruled.
            'is_restockable' => $line !== null && $line->product_variant_id !== null,
            'notes' => $item->notes,
        ];
    }

    /**
     * An order line as a choice on the customer's request form (P2-E4-T2).
     *
     * `returnable_quantity` is the number the form caps the stepper at, and it
     * is the same number `RequestReturn` re-checks under the order's row lock
     * — so an option offered here is one that will be accepted, unless
     * somebody else asked for the same units in between.
     *
     * @return array<string, mixed>
     */
    public static function returnableLine(OrderItem $item): array
    {
        return [
            ...OrderPresenter::item($item),
            'returned_quantity' => $item->returnedQuantity(),
            'returnable_quantity' => $item->returnableQuantity(),
        ];
    }
}
