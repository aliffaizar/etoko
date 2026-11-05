<?php

namespace App\Actions\Product;

use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Updates one variant's identity, overrides and options (P1-E3-T6).
 *
 * Price, weight and dimensions are overrides: a null means "follow the
 * product", so an emptied field is stored as null rather than as zero and the
 * variant goes back to tracking the product (ADR-020).
 *
 * `is_default` is not settable here for the same reason as in
 * `CreateProductVariant`: handing the flag over has to clear the previous
 * holder, which is `SetDefaultProductVariant`.
 */
final class UpdateProductVariant
{
    /**
     * @param  array<string, mixed>  $attributes  Validated variant fields, plus `attribute_value_ids`.
     */
    public function handle(ProductVariant $variant, array $attributes): ProductVariant
    {
        return DB::transaction(function () use ($variant, $attributes): ProductVariant {
            $syncsOptions = array_key_exists('attribute_value_ids', $attributes);
            /** @var array<int, int> $valueIds */
            $valueIds = $attributes['attribute_value_ids'] ?? [];

            unset($attributes['attribute_value_ids'], $attributes['is_default'], $attributes['product_id']);

            $variant->fill($attributes)->save();

            if ($syncsOptions) {
                $variant->attributeValues()->sync($valueIds);
            }

            return $variant;
        });
    }
}
