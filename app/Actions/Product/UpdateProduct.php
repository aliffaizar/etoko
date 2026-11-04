<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Updates a product's own fields and the identity of its default variant
 * (P1-E3-T5).
 *
 * `sku` and `barcode` live on the variant, not the product, but every product
 * has exactly one default variant (ADR-020) and that is the one the product
 * screen edits. Non-default variants belong to the variant editor
 * (P1-E3-T6).
 *
 * Variants are not touched beyond that: their prices and weights are
 * deliberately left null so they keep following the product's.
 */
final class UpdateProduct
{
    /**
     * @param  array<string, mixed>  $attributes  Validated product fields, plus `sku` and `barcode` for the default variant.
     */
    public function handle(Product $product, array $attributes): Product
    {
        return DB::transaction(function () use ($product, $attributes): Product {
            $sku = $attributes['sku'] ?? null;
            $barcode = $attributes['barcode'] ?? null;

            unset($attributes['sku'], $attributes['barcode']);

            $product->fill($attributes)->save();

            if ($sku !== null) {
                $this->applyToDefaultVariant($product, (string) $sku, $barcode === '' ? null : $barcode);
            }

            return $product;
        });
    }

    /**
     * A product that somehow has no default variant gets one, rather than the
     * submitted SKU being dropped on the floor.
     */
    private function applyToDefaultVariant(Product $product, string $sku, ?string $barcode): void
    {
        $variant = $product->variants()->where('is_default', true)->first();

        if ($variant instanceof ProductVariant) {
            $variant->fill(['sku' => $sku, 'barcode' => $barcode])->save();

            return;
        }

        $product->variants()->create([
            'sku' => $sku,
            'barcode' => $barcode,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
