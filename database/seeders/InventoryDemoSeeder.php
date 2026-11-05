<?php

namespace Database\Seeders;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\CreateStockEntry;
use App\Actions\Inventory\PostStockEntry;
use App\Actions\Inventory\SetStockThresholds;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Opening stock for local development: a posted goods-in document per branch,
 * reorder thresholds on part of the catalogue, and a few variants deliberately
 * left below their minimum so the low-stock panel and its alerts have
 * something to show.
 *
 * Built through the same Actions the panel uses, so what the seeder produces
 * can never drift from what the screens produce — the stock figures come from
 * a document that was actually posted, and every figure has the ledger rows
 * that explain it. A seeder that wrote `stocks` directly would leave a branch
 * holding goods no movement accounts for, which is the one state the whole
 * epic exists to prevent.
 *
 * Re-running it is safe: a warehouse that already holds stock is skipped
 * rather than topped up, so the figures stay the ones the documents explain.
 * Never run it against a production database.
 */
class InventoryDemoSeeder extends Seeder
{
    /** Units received per variant, so the figures look like a real delivery. */
    private const OPENING_QUANTITY = 60;

    /** Variants stocked per branch, enough to paginate without being slow to seed. */
    private const VARIANTS_PER_BRANCH = 12;

    /** Minimum set on the stocked variants, so "low" means something. */
    private const MINIMUM = 10;

    /** Variants per branch pushed below their minimum, to populate the alerts. */
    private const LOW_VARIANTS = 2;

    public function run(
        CreateStockEntry $createEntry,
        PostStockEntry $postEntry,
        SetStockThresholds $setThresholds,
        AdjustStock $adjustStock,
    ): void {
        // `product` is eager-loaded because `effectiveCostPrice()` reads
        // through to it, and `Model::preventLazyLoading()` is on.
        $variants = ProductVariant::query()
            ->with('product:id,cost_price')
            ->orderBy('sku')
            ->limit(self::VARIANTS_PER_BRANCH)
            ->get();

        if ($variants->isEmpty()) {
            $this->command?->warn('InventoryDemoSeeder: belum ada varian produk. Jalankan CatalogDemoSeeder lebih dahulu.');

            return;
        }

        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            $warehouse = Warehouse::acrossBranches()
                ->where('branch_id', $branch->getKey())
                ->orderByDesc('is_default')
                ->first();

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $alreadyStocked = Stock::acrossBranches()
                ->where('warehouse_id', $warehouse->getKey())
                ->exists();

            if ($alreadyStocked) {
                continue;
            }

            $this->stockWarehouse(
                $warehouse,
                $variants->all(),
                $createEntry,
                $postEntry,
                $setThresholds,
                $adjustStock,
            );
        }
    }

    /**
     * Receive the opening stock, set thresholds, then draw a couple of
     * variants back down below theirs.
     *
     * The low variants are produced by a second posted document rather than by
     * editing the figure, for the same reason the opening stock is: every
     * figure has to be explainable from the ledger.
     *
     * @param  array<int, ProductVariant>  $variants
     */
    private function stockWarehouse(
        Warehouse $warehouse,
        array $variants,
        CreateStockEntry $createEntry,
        PostStockEntry $postEntry,
        SetStockThresholds $setThresholds,
        AdjustStock $adjustStock,
    ): void {
        $actor = $this->warehouseStaffOf($warehouse);

        $entry = $createEntry->handle($warehouse, [
            'supplier_name' => 'PT Pemasok Awal',
            'reference_no' => 'DO-AWAL-'.$warehouse->code,
            'entry_date' => now()->subDays(3)->toDateString(),
            'notes' => 'Stok awal hasil seeder pengembangan.',
            'items' => array_map(fn (ProductVariant $variant): array => [
                'product_variant_id' => $variant->getKey(),
                'quantity' => self::OPENING_QUANTITY,
                'unit_cost' => $variant->effectiveCostPrice(),
            ], $variants),
        ], $actor);

        $postEntry->handle($entry, $actor);

        $setThresholds->handle($warehouse, $variants, ['min_quantity' => self::MINIMUM]);

        $this->drawDown($warehouse, array_slice($variants, 0, self::LOW_VARIANTS), $adjustStock, $actor);
    }

    /**
     * Bring a couple of variants back down below their minimum, so the
     * low-stock panel and its alerts have something to show.
     *
     * Done as a ledger movement rather than by writing the figure: the whole
     * point of the epic is that no figure exists without the movements that
     * explain it, and a seeder that broke that rule would be seeding the one
     * state the application is built to prevent.
     *
     * @param  array<int, ProductVariant>  $variants
     */
    private function drawDown(
        Warehouse $warehouse,
        array $variants,
        AdjustStock $adjustStock,
        ?User $actor,
    ): void {
        $target = self::MINIMUM - 2;

        foreach ($variants as $variant) {
            $adjustStock->handle(
                $warehouse,
                $variant,
                $target - self::OPENING_QUANTITY,
                'Koreksi stok awal seeder: menyisakan stok di bawah minimum sebagai contoh peringatan.',
                $actor,
            );
        }
    }

    /**
     * Somebody from the branch to put on the documents, so `created_by` and
     * `posted_by` are not empty on every seeded record.
     */
    private function warehouseStaffOf(Warehouse $warehouse): ?User
    {
        return User::query()
            ->where('branch_id', $warehouse->branch_id)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', [
                UserRole::StaffGudang->value,
                UserRole::AdminCabang->value,
            ]))
            ->first();
    }
}
