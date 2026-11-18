<?php

namespace Database\Seeders;

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
 * Stock for the rest of the catalogue, so the demo storefront is not mostly
 * "habis" (out of stock).
 *
 * `InventoryDemoSeeder` stocks a dozen variants per branch — enough for its
 * tests — and this seeder receives every variant it left out, in one posted
 * goods-in document per branch's default warehouse, with quantities that
 * differ per branch so the branch switcher visibly changes what is on offer.
 *
 * Through the same Actions as the small one, so every unit has the ledger row
 * that explains it. Re-running is safe: only variants a warehouse holds no
 * stock row for are received. Never run it against a production database.
 */
class ExtendedInventoryDemoSeeder extends Seeder
{
    public function run(
        CreateStockEntry $createEntry,
        PostStockEntry $postEntry,
        SetStockThresholds $setThresholds,
    ): void {
        $variants = ProductVariant::query()
            ->with('product:id,cost_price')
            ->orderBy('sku')
            ->get();

        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            $warehouse = Warehouse::acrossBranches()
                ->where('branch_id', $branch->getKey())
                ->orderByDesc('is_default')
                ->first();

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $stocked = Stock::acrossBranches()
                ->where('warehouse_id', $warehouse->getKey())
                ->pluck('product_variant_id')
                ->all();

            $missing = $variants->reject(fn (ProductVariant $variant): bool => in_array($variant->getKey(), $stocked, true))
                ->values()
                ->all();

            if ($missing === []) {
                continue;
            }

            $actor = $this->warehouseStaffOf($warehouse);

            $entry = $createEntry->handle($warehouse, [
                'supplier_name' => fake()->randomElement(['PT Sumber Makmur Distribusi', 'CV Nusantara Jaya', 'PT Mitra Grosir Indonesia']),
                'reference_no' => 'DO-LENGKAP-'.$warehouse->code,
                'entry_date' => now()->subDays(2)->toDateString(),
                'notes' => 'Stok katalog lengkap hasil seeder demo.',
                'items' => array_map(fn (ProductVariant $variant): array => [
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => fake()->numberBetween(30, 120),
                    'unit_cost' => $variant->effectiveCostPrice(),
                ], $missing),
            ], $actor);

            $postEntry->handle($entry, $actor);

            $setThresholds->handle($warehouse, $missing, ['min_quantity' => 5]);
        }
    }

    /**
     * Somebody from the branch to put on the document.
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
