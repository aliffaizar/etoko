<?php

namespace App\Enums;

/**
 * How a promotion takes its cut off a product's catalogue price (P2-E8-T2,
 * ADR-067).
 *
 * Both work on the whole rupiah amount and round the cut **down**, so the
 * shop never gives away more than the promotion states.
 */
enum PromotionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Persentase',
            self::Fixed => 'Potongan Tetap',
        };
    }

    /**
     * The rupiah taken off `$amount` by a promotion of this type with `$value`.
     *
     * Never more than `$amount`: a fixed promotion larger than the price makes
     * the item free, not negative.
     */
    public function discountFor(int $amount, int $value): int
    {
        $discount = match ($this) {
            self::Percentage => intdiv($amount * $value, 100),
            self::Fixed => $value,
        };

        return min(max($discount, 0), $amount);
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
