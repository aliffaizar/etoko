<?php

use App\Actions\Inventory\SetStockThresholds;
use App\Events\StockRanLow;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Event;

function thresholds(): SetStockThresholds
{
    return app(SetStockThresholds::class);
}

function someVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

it('writes nothing to the ledger, because a threshold is not a movement', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = someVariant();

    thresholds()->handle($warehouse, [$variant], ['min_quantity' => 5]);

    expect(StockMovement::acrossBranches()->count())->toBe(0)
        ->and(Stock::acrossBranches()->sole()->min_quantity)->toBe(5);
});

it('leaves the stock figure alone', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = someVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(40)->create();

    thresholds()->handle($warehouse, [$variant], ['min_quantity' => 5, 'max_quantity' => 80]);

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(40)
        ->and($stock->min_quantity)->toBe(5)
        ->and($stock->max_quantity)->toBe(80);
});

it('creates a row at zero for a variant that has never been stocked', function () {
    $warehouse = Warehouse::factory()->default()->create();

    thresholds()->handle($warehouse, [someVariant()], ['min_quantity' => 2]);

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(0)
        ->and($stock->branch_id)->toBe($warehouse->branch_id);
});

it('announces low stock when a new minimum rises above what is on the shelf', function () {
    Event::fake([StockRanLow::class]);

    $warehouse = Warehouse::factory()->default()->create();
    $variant = someVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(3)->create();

    thresholds()->handle($warehouse, [$variant], ['min_quantity' => 10]);

    Event::assertDispatchedTimes(StockRanLow::class, 1);
});

it('says nothing when the row was already low', function () {
    Event::fake([StockRanLow::class]);

    $warehouse = Warehouse::factory()->default()->create();
    $variant = someVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(3)->minimum(5)->create();

    thresholds()->handle($warehouse, [$variant], ['min_quantity' => 8]);

    Event::assertNotDispatched(StockRanLow::class);
});

it('clears a maximum and a shelf location when sent empty', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = someVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->create(['max_quantity' => 50, 'shelf_location' => 'A-01']);

    thresholds()->handle($warehouse, [$variant], [
        'min_quantity' => 1,
        'max_quantity' => null,
        'shelf_location' => null,
    ]);

    $stock = Stock::acrossBranches()->sole();

    expect($stock->max_quantity)->toBeNull()
        ->and($stock->shelf_location)->toBeNull();
});

it('does nothing at all when given no variants', function () {
    $warehouse = Warehouse::factory()->default()->create();

    expect(thresholds()->handle($warehouse, [], ['min_quantity' => 5]))->toBe(0)
        ->and(Stock::acrossBranches()->count())->toBe(0);
});
