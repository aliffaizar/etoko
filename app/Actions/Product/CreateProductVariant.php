<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Adds one variant to a product by hand (P1-E3-T6).
 *
 * The generator covers a full grid of options; this covers the combination the
 * grid would not produce — the colour that only exists in one size, the bundle
 * that is sold alongside it.
 *
 * `is_default` is not settable here: handing the flag over is its own
 * operation, `SetDefaultProductVariant`, which has to clear the old holder in
 * the same transaction. The one exception is a product that somehow has no
 * default variant at all, where the new variant takes the flag so the product
 * is never left without the fallback everything downstream reads (ADR-020).
 */
final class CreateProductVariant
{
    /**
     * @param  array<string, mixed>  $attributes  Validated variant fields, plus `attribute_value_ids`.
     */
    public function handle(Product $product, array $attributes): ProductVariant
    {
        return DB::transaction(function () use ($product, $attributes): ProductVariant {
            /** @var array<int, int> $valueIds */
            $valueIds = $attributes['attribute_value_ids'] ?? [];

            unset($attributes['attribute_value_ids'], $attributes['is_default']);

            $variant = $product->variants()->create([
                ...$attributes,
                'is_default' => ! $product->variants()->where('is_default', true)->exists(),
            ]);

            $variant->attributeValues()->sync($valueIds);

            return $variant;
        });
    }
}
