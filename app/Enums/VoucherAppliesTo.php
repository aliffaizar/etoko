<?php

namespace App\Enums;

/**
 * Which goods a voucher covers (P2-E5-T1).
 *
 * `category` covers every product in the chosen categories **and their
 * subcategories** — a voucher on "Elektronik" that skipped "Elektronik ›
 * Ponsel" would read as broken. `product` covers exactly the products listed.
 * The lines a voucher covers are also the ones its minimum spend is measured
 * against (ADR-064).
 */
enum VoucherAppliesTo: string
{
    case All = 'all';
    case Category = 'category';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua produk',
            self::Category => 'Kategori tertentu',
            self::Product => 'Produk tertentu',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $target): array => ['value' => $target->value, 'label' => $target->label()],
            self::cases(),
        );
    }
}
