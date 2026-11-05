<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;

it('reports what may still be sold as on-hand minus reserved', function () {
    $stock = Stock::factory()->quantity(10)->reserved(4)->create();

    expect($stock->available_quantity)->toBe(6);
});

it('never reports a negative sellable quantity', function () {
    $stock = Stock::factory()->quantity(2)->reserved(5)->make();

    expect($stock->available_quantity)->toBe(0);
});

it('reads a missing row as zero rather than unknown', function () {
    expect(Stock::quantityFor(null))->toBe(0)
        ->and(Stock::availableQuantityFor(null))->toBe(0);
});

it('calls a row low only once somebody has set a minimum', function () {
    expect(Stock::factory()->quantity(1)->minimum(0)->make()->isLow())->toBeFalse()
        ->and(Stock::factory()->quantity(5)->minimum(5)->make()->isLow())->toBeTrue()
        ->and(Stock::factory()->quantity(6)->minimum(5)->make()->isLow())->toBeFalse();
});

it('lists only the rows that have fallen to their minimum', function () {
    $warehouse = Warehouse::factory()->default()->create();
    Stock::factory()->inWarehouse($warehouse)->low()->create();
    Stock::factory()->inWarehouse($warehouse)->quantity(50)->minimum(5)->create();
    Stock::factory()->inWarehouse($warehouse)->quantity(0)->minimum(0)->create();

    expect(Stock::acrossBranches()->low()->count())->toBe(1);
});

it('takes the branch of the warehouse it sits in', function () {
    $warehouse = Warehouse::factory()->default()->create();

    $stock = Stock::factory()->inWarehouse($warehouse)->create();

    expect($stock->branch_id)->toBe($warehouse->branch_id);
});

it('refuses two stock rows for the same warehouse and variant', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->create();

    expect(fn () => Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps no updated_at on a ledger row, because it is never updated', function () {
    $movement = StockMovement::factory()->create();

    expect($movement->getAttributes())->not->toHaveKey('updated_at');
});

it('orders movement history newest first, breaking ties by id', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $moment = now();

    $older = StockMovement::factory()->inWarehouse($warehouse)->create(['created_at' => $moment->copy()->subDay()]);
    $first = StockMovement::factory()->inWarehouse($warehouse)->create(['created_at' => $moment]);
    $second = StockMovement::factory()->inWarehouse($warehouse)->create(['created_at' => $moment]);

    expect(StockMovement::acrossBranches()->latestFirst()->pluck('id')->all())
        ->toBe([$second->id, $first->id, $older->id]);
});

it('labels every movement type, so no ledger row renders blank', function () {
    foreach (StockMovementType::cases() as $type) {
        expect($type->label())->not->toBe('');
    }
});
