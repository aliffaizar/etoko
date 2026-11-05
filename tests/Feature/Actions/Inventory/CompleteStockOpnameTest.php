<?php

use App\Actions\Inventory\CompleteStockOpname;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

function completeOpname(): CompleteStockOpname
{
    return app(CompleteStockOpname::class);
}

function opnameVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

it('writes the shortfall of a line that counted less than the system believed', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = opnameVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(12)->create();

    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(12, 9)->create();

    $actor = User::factory()->adminCabang($warehouse->branch)->create();
    $completed = completeOpname()->handle($opname, $actor);

    $movement = StockMovement::acrossBranches()->sole();

    expect($completed->status)->toBe(StockOpnameStatus::Completed)
        ->and($completed->approved_by)->toBe($actor->getKey())
        ->and($completed->completed_at)->not->toBeNull()
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(9)
        ->and($movement->type)->toBe(StockMovementType::OpnameAdjustment)
        ->and($movement->quantity_change)->toBe(-3)
        ->and($movement->notes)->toContain('sistem 12')
        ->and($movement->notes)->toContain('hasil hitung 9');
});

it('writes the surplus of a line that counted more than the system believed', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = opnameVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(5)->create();

    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(5, 8)->create();

    completeOpname()->handle($opname);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(8)
        ->and(StockMovement::acrossBranches()->sole()->quantity_change)->toBe(3);
});

it('posts the variance and not the counted figure, so stock that moved during the count survives', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = opnameVariant();
    // The shelves said 9 where the system said 12, so the variance is -3.
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(12, 9)->create();

    // Two units sold while the shelves were being walked.
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(10)->create();

    completeOpname()->handle($opname);

    // 10 - 3 = 7, not 9: nine were on the shelf when counted, two have left since.
    expect(Stock::acrossBranches()->sole()->quantity)->toBe(7);
});

it('writes nothing for a line nobody counted', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $counted = opnameVariant();
    $skipped = opnameVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($counted)->quantity(4)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($skipped)->quantity(7)->create();

    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($counted)->counted(4, 3)->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($skipped)->create();

    completeOpname()->handle($opname);

    expect(StockMovement::acrossBranches()->count())->toBe(1)
        ->and(Stock::acrossBranches()->where('product_variant_id', $skipped->id)->sole()->quantity)->toBe(7);
});

it('writes nothing for a line that counted exactly what was expected', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = opnameVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(6)->create();

    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(6, 6)->create();

    completeOpname()->handle($opname);

    expect(StockMovement::acrossBranches()->count())->toBe(0)
        ->and($opname->refresh()->status)->toBe(StockOpnameStatus::Completed);
});

it('refuses to complete a session where nothing was counted', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant(opnameVariant())->create();

    expect(fn () => completeOpname()->handle($opname))->toThrow(ValidationException::class);

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::Draft);
});

it('refuses to complete the same session twice', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = opnameVariant();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(10)->create();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->inProgress()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant($variant)->counted(10, 8)->create();

    completeOpname()->handle($opname);

    expect(fn () => completeOpname()->handle($opname->refresh()))->toThrow(ValidationException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(8)
        ->and(StockMovement::acrossBranches()->count())->toBe(1);
});

it('refuses to complete a cancelled session', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $opname = StockOpname::factory()->inWarehouse($warehouse)->cancelled()->create();
    StockOpnameItem::factory()->for($opname, 'stockOpname')->forVariant(opnameVariant())->counted(5, 4)->create();

    expect(fn () => completeOpname()->handle($opname))->toThrow(ValidationException::class);
});

it('computes a line difference rather than storing it', function () {
    $counted = StockOpnameItem::factory()->counted(10, 7)->make();
    $uncounted = StockOpnameItem::factory()->make(['system_quantity' => 10]);

    expect($counted->difference())->toBe(-3)
        ->and($counted->isCounted())->toBeTrue()
        ->and($uncounted->difference())->toBeNull()
        ->and($uncounted->isCounted())->toBeFalse();
});
