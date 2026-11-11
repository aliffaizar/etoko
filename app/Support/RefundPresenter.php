<?php

namespace App\Support;

use App\Enums\RefundMethod;
use App\Models\Refund;

/**
 * How refunds reach the screens (P2-E4-T4).
 *
 * Three screens read a refund — the panel's refund listing, the return detail
 * it belongs to, and the customer's own order page — and they must agree about
 * what a status is called and which figure is the one that counts. Keeping the
 * shapes here is the same arrangement `PaymentPresenter` has, for the same
 * reason.
 *
 * `method` is read from the **snapshot** on the row, never from configuration:
 * "this went back by bank transfer" must stay true however the shop's payment
 * methods change afterwards (ADR-007).
 *
 * The shapes mirror the TypeScript types in `resources/js/types/return.ts`.
 */
final class RefundPresenter
{
    /**
     * One row of the panel's refund listing.
     *
     * The order and the branch are expected to be loaded by the caller, so
     * drawing a page costs no query per row.
     *
     * @return array<string, mixed>
     */
    public static function row(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'code' => $refund->code,
            'amount' => $refund->amount,
            'method' => $refund->method->value,
            'method_label' => $refund->method->label(),
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'reference' => $refund->reference,
            'account_info' => $refund->account_info,
            'failure_reason' => $refund->failure_reason,
            'notes' => $refund->notes,
            'return_id' => $refund->return_id,
            'return_code' => $refund->relationLoaded('return') ? $refund->return?->code : null,
            'payment_id' => $refund->payment_id,
            'payment_reference' => $refund->relationLoaded('payment') ? $refund->payment?->reference : null,
            'processed_by' => $refund->relationLoaded('processedBy') ? $refund->processedBy?->name : null,
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
            'order' => $refund->relationLoaded('order')
                ? [
                    'id' => $refund->order->id,
                    'order_number' => $refund->order->order_number,
                    'customer_name' => $refund->order->customer_name,
                    'payment_status' => $refund->order->payment_status->value,
                    'payment_status_label' => $refund->order->payment_status->label(),
                    'grand_total' => $refund->order->grand_total,
                ]
                : null,
            'branch' => $refund->relationLoaded('branch')
                ? ['id' => $refund->branch->id, 'name' => $refund->branch->name]
                : null,
        ];
    }

    /**
     * One refund, with the capability flags a screen needs to offer buttons.
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public static function detail(Refund $refund, array $can = []): array
    {
        return [
            ...self::row($refund),
            'blocked' => ['settle' => $refund->settlementBlockedReason()],
            'can' => $can,
        ];
    }

    /**
     * The refund as the customer's own order page reads it.
     *
     * Only the three things a customer can act on: how much, which way, and
     * whether it has arrived. The internal note and who pressed the button are
     * the shop's own workings.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'code' => $refund->code,
            'amount' => $refund->amount,
            'method' => $refund->method->value,
            'method_label' => $refund->method->label(),
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'reference' => $refund->reference,
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }

    /**
     * The ways of refunding a screen may offer, each with its verdict.
     *
     * An unavailable method is **kept** rather than filtered out, the same
     * choice `PaymentPresenter::method()` makes: "Pengembalian sebagai saldo
     * toko belum tersedia" tells a staff member what to do, and a missing
     * option tells them nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function methodChoices(): array
    {
        return array_map(
            fn (RefundMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'is_automatic' => $method->isAutomatic(),
                'needs_account_info' => $method->needsAccountInfo(),
                'is_available' => $method->unavailableReason() === null,
                'unavailable_reason' => $method->unavailableReason(),
            ],
            RefundMethod::cases(),
        );
    }
}
