<?php

namespace App\Actions\Inventory;

use App\Enums\StockOpnameStatus;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use App\Support\ProductListQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a stock count and fills it with the lines to be counted (P1-E4-T7).
 *
 * Each line carries a **snapshot** of what the system believed at this moment.
 * That snapshot is what makes the count meaningful later: completion posts the
 * variance against it, so stock that moves legitimately while the shelves are
 * being walked is preserved rather than counted away. See
 * `CompleteStockOpname`.
 *
 * Which variants get counted is a choice, because counting everything is rare:
 *
 * - **A category** — the usual case, counting one aisle at a time. Includes
 *   the category's subcategories, like every other category filter.
 * - **Everything the warehouse holds** — every variant with a stock row,
 *   which is what a full count means in practice. Variants the warehouse has
 *   never stocked are left out: a line of "system 0, counted 0" for the whole
 *   catalogue is noise that hides the lines that matter.
 */
final class CreateStockOpname
{
    /** Lines one session may hold, so a full count stays a page somebody can work through. */
    public const MAX_ITEMS = 1000;

    /**
     * @param  array<string, mixed>  $attributes  Validated counted_at, notes and category_id.
     *
     * @throws ValidationException When the chosen scope holds nothing, or more than `MAX_ITEMS`.
     */
    public function handle(Warehouse $warehouse, array $attributes, ?User $actor = null): StockOpname
    {
        $variantIds = $this->variantIdsToCount($warehouse, $attributes['category_id'] ?? null);

        if ($variantIds === []) {
            throw ValidationException::withMessages([
                'category_id' => 'Tidak ada varian untuk dihitung pada lingkup ini. Pilih kategori lain, atau catat barang masuk lebih dahulu.',
            ]);
        }

        if (count($variantIds) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'category_id' => 'Lingkup ini berisi '.count($variantIds).' varian, melebihi batas '
                    .self::MAX_ITEMS.' varian per sesi. Pecah opname per kategori.',
            ]);
        }

        return DB::transaction(function () use ($warehouse, $attributes, $actor, $variantIds): StockOpname {
            $opname = StockOpname::create([
                'code' => DocumentNumber::next(DocumentNumber::PREFIX_STOCK_OPNAME),
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->getKey(),
                'status' => StockOpnameStatus::Draft,
                'counted_at' => $attributes['counted_at'],
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor?->getKey(),
            ]);

            StockOpnameItem::insert($this->lines($opname, $warehouse, $variantIds));

            return $opname;
        });
    }

    /**
     * @return array<int, int>
     */
    private function variantIdsToCount(Warehouse $warehouse, ?int $categoryId): array
    {
        if ($categoryId !== null) {
            return ProductVariant::query()
                ->whereHas('product', fn ($product) => $product
                    ->whereIn('category_id', ProductListQuery::categoryBranchIds($categoryId)))
                ->orderBy('sku')
                ->pluck('id')
                ->all();
        }

        return Stock::acrossBranches()
            ->where('warehouse_id', $warehouse->getKey())
            ->orderBy('product_variant_id')
            ->pluck('product_variant_id')
            ->all();
    }

    /**
     * The lines of the session, each snapshotting the figure the system holds
     * right now.
     *
     * The figures are read in one query and a missing row counts as zero, so a
     * thousand-line session costs one query rather than a thousand.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, array<string, mixed>>
     */
    private function lines(StockOpname $opname, Warehouse $warehouse, array $variantIds): array
    {
        $figures = Stock::acrossBranches()
            ->where('warehouse_id', $warehouse->getKey())
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('quantity', 'product_variant_id');

        return array_map(fn (int $variantId): array => [
            'stock_opname_id' => $opname->getKey(),
            'product_variant_id' => $variantId,
            'system_quantity' => (int) ($figures[$variantId] ?? 0),
            'counted_quantity' => null,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $variantIds);
    }
}
