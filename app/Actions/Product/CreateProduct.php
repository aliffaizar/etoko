<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Creates a product together with the default variant it cannot be sold
 * without (P1-E3-T5, ADR-020).
 *
 * Stock, cart lines and order lines all point at a variant, never at a
 * product, so a product with no variant could be filed but never sold. Every
 * product therefore owns exactly one variant from the moment it exists; the
 * variant editor (P1-E3-T6) adds the rest for a `variable` product.
 *
 * The variant is created bare: its price, weight and dimensions stay null and
 * fall back to the product's own, so the two can never drift apart.
 *
 * The slug is not set here: the create form does not ask for one and
 * `ProductObserver` derives it from the name (P1-E3-T8).
 */
final class CreateProduct
{
    /**
     * @param  array<string, mixed>  $attributes  Validated product fields, plus `sku` and `barcode` for the default variant.
     */
    public function handle(array $attributes): Product
    {
        return DB::transaction(function () use ($attributes): Product {
            $sku = (string) $attributes['sku'];
            $barcode = $attributes['barcode'] ?? null;

            unset($attributes['sku'], $attributes['barcode']);

            $product = Product::create($attributes);

            $product->variants()->create([
                'sku' => $sku,
                'barcode' => $barcode === '' ? null : $barcode,
                'is_default' => true,
                'is_active' => true,
            ]);

            return $product;
        });
    }
}
