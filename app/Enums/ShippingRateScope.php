<?php

namespace App\Enums;

/**
 * How wide a manual shipping rate reaches (P2-E2-T1).
 *
 * `shipping_rates.destination_type` in docs/03-data-model.md. The order of
 * `specificity()` is the whole point of the column: a rate written for one
 * district must beat the rate written for its province, and the province rate
 * must beat the catch-all. Without an ordering, "which rate applies?" would
 * depend on insertion order.
 */
enum ShippingRateScope: string
{
    case District = 'district';
    case City = 'city';
    case Province = 'province';

    /** Every destination the courier serves — the fallback rate. */
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::District => 'Kecamatan',
            self::City => 'Kota/Kabupaten',
            self::Province => 'Provinsi',
            self::All => 'Semua tujuan',
        };
    }

    /**
     * How specific this scope is; higher wins.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::All => 0,
            self::Province => 1,
            self::City => 2,
            self::District => 3,
        };
    }

    /**
     * The column on `orders` / `addresses` whose text this scope is matched
     * against, or null for the catch-all which matches without comparing
     * anything.
     */
    public function destinationNameKey(): ?string
    {
        return match ($this) {
            self::District => 'district_name',
            self::City => 'city_name',
            self::Province => 'province_name',
            self::All => null,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            self::cases(),
        );
    }
}
