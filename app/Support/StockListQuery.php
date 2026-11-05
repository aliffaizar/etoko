<?php

namespace App\Support;

use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/**
 * The branch stock listing's filters, as a query (P1-E4-T3).
 *
 * The listing is over **variants**, not over `stocks` rows, because a `stocks`
 * row only exists once something has been said about the pair. Listing the
 * table directly would hide every variant the warehouse has never stocked —
 * which is exactly the set somebody arriving at this screen wants to set a
 * minimum for, or receive the first delivery of.
 *
 * So each row is a variant with its warehouse's figures attached, and a
 * variant with no row reads as zero. `stock_quantity`, `stock_reserved` and
 * `stock_available` are correlated sub-selects rather than joins, because the
 * sort has to work on the zero as well as on the stored figure — a joined row
 * that is absent cannot be ordered.
 */
final class StockListQuery
{
    /**
     * @param  array{search: string, category: string, state: string, sort: string, direction: string}  $filters
     * @return Builder<ProductVariant>
     */
    public static function from(Warehouse $warehouse, array $filters): Builder
    {
        $warehouseId = $warehouse->getKey();

        return ProductVariant::query()
            ->with([
                'product:id,name,slug,category_id,type,base_price',
                'product.category:id,name',
                'stocks' => fn ($stocks) => $stocks->where('warehouse_id', $warehouseId),
            ])
            ->select('product_variants.*')
            ->addSelect([
                'stock_quantity' => self::figure($warehouseId, 'quantity'),
                'stock_reserved' => self::figure($warehouseId, 'reserved_quantity'),
                'stock_minimum' => self::figure($warehouseId, 'min_quantity'),
            ])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('sku', 'like', "%{$filters['search']}%")
                    ->orWhere('barcode', 'like', "%{$filters['search']}%")
                    ->orWhere('name', 'like', "%{$filters['search']}%")
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('name', 'like', "%{$filters['search']}%")),
            ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->whereHas('product', fn (Builder $product) => $product
                    ->whereIn('category_id', ProductListQuery::categoryBranchIds((int) $filters['category']))))
            ->when($filters['state'] === 'low', fn (Builder $query) => $query
                ->whereHas('stocks', fn (Builder $stocks) => $stocks
                    ->where('warehouse_id', $warehouseId)
                    ->where('min_quantity', '>', 0)
                    ->whereColumn('quantity', '<=', 'min_quantity')))
            // "Out of stock" has to include the variants with no row at all,
            // which is why it is a `whereDoesntHave` on "holds something"
            // rather than a `whereHas` on "holds nothing".
            ->when($filters['state'] === 'out', fn (Builder $query) => $query
                ->whereDoesntHave('stocks', fn (Builder $stocks) => $stocks
                    ->where('warehouse_id', $warehouseId)
                    ->where('quantity', '>', 0)))
            ->when($filters['state'] === 'in_stock', fn (Builder $query) => $query
                ->whereHas('stocks', fn (Builder $stocks) => $stocks
                    ->where('warehouse_id', $warehouseId)
                    ->where('quantity', '>', 0)));
    }

    /**
     * The same set, ordered as the listing orders it.
     *
     * `stock_available` is not a column anywhere: it is on-hand minus what
     * checkout is holding, so sorting by it means sorting by that difference.
     *
     * @param  array{search: string, category: string, state: string, sort: string, direction: string}  $filters
     * @return Builder<ProductVariant>
     */
    public static function ordered(Warehouse $warehouse, array $filters): Builder
    {
        $query = self::from($warehouse, $filters);
        $direction = $filters['direction'] === 'desc' ? 'desc' : 'asc';

        $query = match ($filters['sort']) {
            'stock_quantity' => $query->orderBy(self::figure($warehouse->getKey(), 'quantity'), $direction),
            'stock_available' => $query->orderBy(self::available($warehouse->getKey()), $direction),
            default => $query->orderBy('sku', $direction),
        };

        // Tie-broken by id, so a page of variants that all hold zero does not
        // reshuffle between requests and paginate the same row twice.
        return $query->orderBy('product_variants.id');
    }

    /**
     * One figure of the warehouse's row for the variant, as zero when there is
     * no row.
     *
     * The column arrives as an enum of literals rather than a string, so no
     * caller can ever put anything of its own into the SQL.
     *
     * @param  'quantity'|'reserved_quantity'|'min_quantity'  $column
     * @return Builder<Stock>
     */
    private static function figure(int $warehouseId, string $column): Builder
    {
        $expression = match ($column) {
            'quantity' => 'coalesce(quantity, 0)',
            'reserved_quantity' => 'coalesce(reserved_quantity, 0)',
            'min_quantity' => 'coalesce(min_quantity, 0)',
        };

        return self::figureQuery($warehouseId)->selectRaw($expression);
    }

    /**
     * @return Builder<Stock>
     */
    private static function available(int $warehouseId): Builder
    {
        return self::figureQuery($warehouseId)->selectRaw('coalesce(quantity - reserved_quantity, 0)');
    }

    /**
     * The warehouse's row for the variant of the surrounding query, if it has
     * one.
     *
     * @return Builder<Stock>
     */
    private static function figureQuery(int $warehouseId): Builder
    {
        return Stock::acrossBranches()
            ->whereColumn('product_variant_id', 'product_variants.id')
            ->where('warehouse_id', $warehouseId)
            ->limit(1);
    }
}
