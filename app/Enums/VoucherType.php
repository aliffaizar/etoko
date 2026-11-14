<?php

namespace App\Enums;

/**
 * What a voucher takes off (P2-E5-T1).
 *
 * `percentage` and `fixed` come off the **goods** the voucher covers, so their
 * discount is shared across those order lines (`order_items.discount_amount`)
 * and a returned unit is refunded at the price actually paid for it.
 * `free_shipping` comes off the **delivery charge** and touches no line: it
 * only exists once a courier has been chosen, and a pickup has nothing to
 * take it off (ADR-064).
 */
enum VoucherType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case FreeShipping = 'free_shipping';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Persentase',
            self::Fixed => 'Potongan Tetap',
            self::FreeShipping => 'Gratis Ongkir',
        };
    }

    /**
     * Whether the discount comes off the delivery charge rather than the goods.
     */
    public function discountsShipping(): bool
    {
        return $this === self::FreeShipping;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
