<?php

use App\Enums\StockMovementType;
use App\Events\StockRanLow;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Branch\BranchContext;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function ledger(): StockLedger
{
    return app(StockLedger::class);
}

function variantFor(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

it('creates the stock row on the first movement, because a missing row means zero', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();

    $movement = DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::EntryIn,
        quantityChange: 12,
    ));

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(12)
        ->and($stock->branch_id)->toBe($warehouse->branch_id)
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity_after)->toBe(12)
        ->and($movement->quantity_change)->toBe(12)
        ->and($movement->branch_id)->toBe($warehouse->branch_id);
});

it('records the figure before and after, so the ledger explains the stock row', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(40)->create();

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -15,
    ));

    $movement = StockMovement::acrossBranches()->sole();

    expect($movement->quantity_before)->toBe(40)
        ->and($movement->quantity_after)->toBe(25)
        ->and($movement->type)->toBe(StockMovementType::IssueOut)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(25);
});

it('refuses to take on-hand stock below zero', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(3)->create();

    expect(fn () => DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -4,
    )))->toThrow(InsufficientStockException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(3)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses to issue stock that checkout is already holding', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(10)->reserved(8)->create();

    expect(fn () => DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -5,
    )))->toThrow(InsufficientStockException::class);
});

it('lets a reservation be consumed, moving on-hand and reserved together', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(10)->reserved(10)->create();

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::SaleOut,
        quantityChange: -4,
        reservedChange: -4,
    ));

    $stock = Stock::acrossBranches()->sole();

    expect($stock->quantity)->toBe(6)
        ->and($stock->reserved_quantity)->toBe(6)
        ->and($stock->available_quantity)->toBe(0);
});

it('refuses a movement of zero, which would record nothing', function () {
    $warehouse = Warehouse::factory()->default()->create();

    expect(fn () => DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: variantFor(),
        type: StockMovementType::ManualAdjustment,
        quantityChange: 0,
    )))->toThrow(LogicException::class);
});

it('points the movement at the document that caused it', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $actor = User::factory()->staffGudang($warehouse->branch)->create();

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: variantFor(),
        type: StockMovementType::EntryIn,
        quantityChange: 5,
        reference: $warehouse,
        unitCost: 12_500,
        notes: 'Kiriman supplier',
        actor: $actor,
    ));

    $movement = StockMovement::acrossBranches()->sole();

    expect($movement->reference_type)->toBe($warehouse->getMorphClass())
        ->and($movement->reference_id)->toBe($warehouse->getKey())
        ->and($movement->unit_cost)->toBe(12_500)
        ->and($movement->notes)->toBe('Kiriman supplier')
        ->and($movement->created_by)->toBe($actor->getKey());
});

it('announces low stock only on the movement that crosses the minimum', function () {
    Event::fake([StockRanLow::class]);

    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(6)->minimum(5)->create();

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -1,
    ));

    Event::assertDispatchedTimes(StockRanLow::class, 1);

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -1,
    ));

    Event::assertDispatchedTimes(StockRanLow::class, 1);
});

it('says nothing about a variant nobody set a minimum for', function () {
    Event::fake([StockRanLow::class]);

    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(1)->create();

    DB::transaction(fn () => ledger()->apply(
        warehouse: $warehouse,
        variant: $variant,
        type: StockMovementType::IssueOut,
        quantityChange: -1,
    ));

    Event::assertNotDispatched(StockRanLow::class);
});

it('writes nothing at all when the transaction rolls back', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = variantFor();

    try {
        DB::transaction(function () use ($warehouse, $variant): void {
            ledger()->apply(
                warehouse: $warehouse,
                variant: $variant,
                type: StockMovementType::EntryIn,
                quantityChange: 7,
            );

            throw new RuntimeException('something later failed');
        });
    } catch (RuntimeException) {
        // The point of the test is what the database looks like afterwards.
    }

    expect(Stock::acrossBranches()->count())->toBe(0)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('keeps each branch to its own stock rows', function () {
    $mine = Warehouse::factory()->default()->create();
    $theirs = Warehouse::factory()->default()->for(Branch::factory())->create();
    $variant = variantFor();

    foreach ([$mine, $theirs] as $warehouse) {
        DB::transaction(fn () => ledger()->apply(
            warehouse: $warehouse,
            variant: $variant,
            type: StockMovementType::EntryIn,
            quantityChange: 5,
        ));
    }

    app(BranchContext::class)->restrictTo($mine->branch_id);

    expect(Stock::query()->count())->toBe(1)
        ->and(Stock::query()->sole()->branch_id)->toBe($mine->branch_id)
        ->and(StockMovement::query()->count())->toBe(1);
});
