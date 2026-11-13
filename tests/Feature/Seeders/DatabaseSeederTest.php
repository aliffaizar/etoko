<?php

use App\Enums\StockTransferStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/*
 * DatabaseSeeder itself (P1-E8-T1, P1-E8-T3) — the regression test for CRIT-01.
 *
 * The other seeder tests call each seeder directly, which is why the command
 * the documentation tells people to run could be broken for two days without
 * anything turning red: `DatabaseSeeder` carried `WithoutModelEvents`, which
 * switched off every observer underneath it, and `migrate:fresh --seed` died
 * on the first category because nothing filled in its slug.
 *
 * So this test runs the entry point, not its children. If model events are
 * ever switched off there again, this is what says so.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('runs the documented seed command from end to end', function () {
    expect(Category::count())->toBeGreaterThan(0)
        ->and(Product::count())->toBeGreaterThan(0)
        ->and(ProductVariant::count())->toBeGreaterThan(0)
        ->and(Order::acrossBranches()->count())->toBeGreaterThan(0)
        ->and(Stock::acrossBranches()->count())->toBeGreaterThan(0)
        ->and(User::count())->toBeGreaterThan(0);
});

it('lets the observers fill in every derived column (P1-E3-T8)', function () {
    // The exact failure CRIT-01 reported: `categories.slug` is NOT NULL with no
    // default, and `CategoryObserver::creating()` is the only thing that fills
    // it. The same observers derive product slugs and normalise SKUs.
    expect(Category::whereNull('slug')->orWhere('slug', '')->count())->toBe(0)
        ->and(Product::whereNull('slug')->orWhere('slug', '')->count())->toBe(0)
        ->and(ProductVariant::whereNull('sku')->orWhere('sku', '')->count())->toBe(0);
});

it('writes the audit trail while it seeds (BR-9)', function () {
    // `Auditable` is a model-event listener too, so seeding without events left
    // the audit trail empty — which is exactly the trail BR-9 asks for.
    expect(AuditLog::count())->toBeGreaterThan(0);
});

it('leaves stocks reconcilable against the ledger (ADR-024)', function () {
    $movements = DB::table('stock_movements')
        ->selectRaw('warehouse_id, product_variant_id, sum(quantity_change) as net')
        ->groupBy('warehouse_id', 'product_variant_id')
        ->get()
        ->keyBy(fn (object $row): string => "{$row->warehouse_id}:{$row->product_variant_id}");

    $drifted = Stock::acrossBranches()->get()->filter(function (Stock $stock) use ($movements): bool {
        $key = "{$stock->warehouse_id}:{$stock->product_variant_id}";

        return $stock->quantity !== (int) ($movements[$key]->net ?? 0);
    });

    expect($drifted)->toBeEmpty()
        ->and(Stock::acrossBranches()->where('quantity', '<', 0)->count())->toBe(0)
        ->and(Stock::acrossBranches()->whereColumn('reserved_quantity', '>', 'quantity')->count())->toBe(0);
});

it('seeds one stock transfer at each step worth looking at (P2-E3)', function () {
    $statuses = StockTransfer::acrossBranches()->orderBy('id')->pluck('status')->all();

    expect($statuses)->toBe([
        StockTransferStatus::Requested,
        StockTransferStatus::InTransit,
        StockTransferStatus::PartiallyReceived,
    ]);
});
