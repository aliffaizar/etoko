<?php

namespace App\Support;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;

/**
 * The variant picker behind a stock document's lines (P1-E4-T5, T6).
 *
 * A catalogue can hold thousands of variants, so the form never receives all
 * of them: it sends what has been typed and gets back the matches, through an
 * Inertia partial reload of this one prop. That keeps the picker on the
 * existing document routes rather than needing a JSON endpoint of its own,
 * and keeps the page's payload the size of a page of results.
 *
 * Each option carries the warehouse's current figure, because the person
 * typing a goods-out line needs to know what is there before they type a
 * number — and the alternative is finding out only when posting fails.
 */
final class StockVariantOptions
{
    /** Matches returned per search, enough to pick from without being a listing. */
    public const LIMIT = 20;

    /**
     * @return array<int, array{
     *     value: int, sku: string, label: string, product_name: string,
     *     on_hand: int, available: int
     * }>
     */
    public static function search(Warehouse $warehouse, string $search): array
    {
        $term = trim($search);

        $variants = ProductVariant::query()
            ->with(['product:id,name', 'stocks' => fn ($stocks) => $stocks
                ->where('warehouse_id', $warehouse->getKey())])
            ->active()
            ->when($term !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$term}%")),
            ))
            ->orderBy('sku')
            ->limit(self::LIMIT)
            ->get();

        return $variants
            ->map(function (ProductVariant $variant) use ($warehouse): array {
                $stock = Stock::orZeroed($variant->stocks->firstWhere('warehouse_id', $warehouse->getKey()));

                return [
                    'value' => $variant->id,
                    'sku' => $variant->sku,
                    'label' => $variant->displayName(),
                    'product_name' => $variant->product->name,
                    'on_hand' => $stock->quantity,
                    'available' => $stock->available_quantity,
                ];
            })
            ->all();
    }
}
