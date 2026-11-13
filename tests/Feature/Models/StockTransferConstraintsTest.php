<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * What a stock transfer pins in place, and the ledger debts paid with it
 * (P2-E3-T1, LOW-04, LOW-05).
 */

it('refuses to hard-delete a variant that has stock history', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(3)->create();

    expect(fn () => $variant->forceDelete())->toThrow(QueryException::class);
    expect(Stock::acrossBranches()->where('product_variant_id', $variant->id)->count())->toBe(1);
});

it('refuses a negative stocks figure written around the ledger', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('CHECK constraints are only added on MySQL (LOW-05).');
    }

    $stock = Stock::factory()->quantity(2)->create();

    expect(fn () => DB::table('stocks')->where('id', $stock->id)->update(['quantity' => -1]))
        ->toThrow(QueryException::class);
});

it('keeps a branch and its warehouse while a transfer names them', function () {
    $from = Warehouse::factory()->for(Branch::factory())->create();
    $to = Warehouse::factory()->default()->for(Branch::factory())->create();
    StockTransfer::factory()->between($from, $to)->create();

    expect($from->deletionBlockedReason())->toContain('dokumen transfer stok')
        ->and($from->branch->deletionBlockedReason())->toContain('transfer stok');
});
