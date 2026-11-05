<?php

namespace App\Observers;

use App\Models\ProductVariant;

/**
 * Normalises a variant's SKU and barcode before they reach the unique
 * indexes (P1-E3-T8).
 *
 * The indexes on `sku` and `barcode` are the real guarantee of uniqueness, and
 * on MySQL's default collation they are case-insensitive — but SQLite's are
 * not, and the test suite runs there. Upper-casing the SKU in one place means
 * `kps-16` and `KPS-16` collide on both, instead of the rule holding only
 * where a form request happened to apply it.
 *
 * An emptied barcode field arrives as `''`, which the unique index would treat
 * as a real value and let exactly one variant hold. It becomes null, which the
 * index ignores, so "no barcode" stays available to every variant.
 */
class ProductVariantObserver
{
    public function saving(ProductVariant $variant): void
    {
        $variant->sku = mb_strtoupper(trim($variant->sku));

        $variant->barcode = $this->nullIfBlank($variant->barcode);
        $variant->name = $this->nullIfBlank($variant->name);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
