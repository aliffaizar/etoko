<?php

namespace App\Support;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Pricing\VoucherOutcome;

/**
 * Shapes vouchers for the screens (P2-E5-T2, T3).
 *
 * `forBasket()` is what the cart and checkout screens read; the admin shapes
 * are `listRow()` and `detail()`.
 */
final class VoucherPresenter
{
    /**
     * The applied voucher on a basket, or null when there is none.
     *
     * `shipping_discount` is null while the delivery charge is not known; the
     * checkout screen then works out `min(cost, max_discount)` for the courier
     * the customer picks, the same rule `VoucherValidator` applies when
     * `PlaceOrder` asks it.
     *
     * @return array<string, mixed>|null
     */
    public static function forBasket(?VoucherOutcome $outcome): ?array
    {
        if (! $outcome instanceof VoucherOutcome) {
            return null;
        }

        $voucher = $outcome->voucher;

        return [
            'code' => $voucher->code,
            'name' => $voucher->name,
            'benefit' => $voucher->benefitLabel(),
            'type' => $voucher->type->value,
            'discounts_shipping' => $voucher->type->discountsShipping(),
            'max_discount' => $voucher->max_discount,
            'is_accepted' => $outcome->isAccepted(),
            'rejection' => $outcome->rejection,
            'goods_discount' => $outcome->goodsDiscount,
            'shipping_discount' => $outcome->shippingDiscount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listRow(Voucher $voucher, User $actor): array
    {
        $state = $voucher->state();
        $canDelete = $actor->can('delete', $voucher);
        $blocked = $canDelete ? $voucher->deletionBlockedReason() : null;

        return [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'type' => ['value' => $voucher->type->value, 'label' => $voucher->type->label()],
            'benefit' => $voucher->benefitLabel(),
            'min_order_amount' => $voucher->min_order_amount,
            'quota' => $voucher->quota,
            'used_count' => $voucher->used_count,
            'starts_at' => $voucher->starts_at?->toIso8601String(),
            'ends_at' => $voucher->ends_at?->toIso8601String(),
            'is_active' => $voucher->is_active,
            'state' => ['value' => $state->value, 'label' => $state->label(), 'tone' => $state->tone()],
            'can' => [
                'update' => $actor->can('update', $voucher),
                'delete' => $canDelete && $blocked === null,
            ],
            'delete_blocked_reason' => $blocked,
        ];
    }

    /**
     * The voucher as the edit form reads it, plus its latest redemptions.
     *
     * @return array<string, mixed>
     */
    public static function detail(Voucher $voucher): array
    {
        $state = $voucher->state();

        return [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'description' => $voucher->description,
            'type' => $voucher->type->value,
            'value' => $voucher->value,
            'max_discount' => $voucher->max_discount,
            'min_order_amount' => $voucher->min_order_amount,
            'quota' => $voucher->quota,
            'used_count' => $voucher->used_count,
            'per_user_limit' => $voucher->per_user_limit,
            'applies_to' => $voucher->applies_to->value,
            'channel' => $voucher->channel->value,
            'starts_at' => $voucher->starts_at?->toIso8601String(),
            'ends_at' => $voucher->ends_at?->toIso8601String(),
            'is_active' => $voucher->is_active,
            'branch_ids' => $voucher->branches->modelKeys(),
            'category_ids' => $voucher->categories->modelKeys(),
            'product_ids' => $voucher->products->modelKeys(),
            'state' => ['value' => $state->value, 'label' => $state->label(), 'tone' => $state->tone()],
            'benefit' => $voucher->benefitLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usage(VoucherUsage $usage): array
    {
        return [
            'id' => $usage->id,
            'order_id' => $usage->order_id,
            'order_number' => $usage->order->order_number,
            'customer_name' => $usage->order->customer_name,
            'discount_amount' => $usage->discount_amount,
            'used_at' => $usage->used_at->toIso8601String(),
            'released_at' => $usage->released_at?->toIso8601String(),
        ];
    }
}
