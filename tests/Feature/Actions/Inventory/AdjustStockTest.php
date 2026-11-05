<?php

use App\Actions\Inventory\AdjustStock;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;

function adjust(): AdjustStock
{
    return app(AdjustStock::class);
}

it('adds to the figure and records who said so and why', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    $actor = User::factory()->staffGudang()->for($warehouse->branch)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(10)->create();

    adjust()->handle($warehouse, $variant, 3, 'Dua dus ketemu di rak belakang', $actor);

    $movement = StockMovement::acrossBranches()->sole();

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(13)
        ->and($movement->type)->toBe(StockMovementType::ManualAdjustment)
        ->and($movement->quantity_change)->toBe(3)
        ->and($movement->notes)->toBe('Dua dus ketemu di rak belakang')
        ->and($movement->created_by)->toBe($actor->getKey());
});

it('subtracts from the figure for a negative adjustment', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(10)->create();

    adjust()->handle($warehouse, $variant, -4, 'Satu dus rusak kena air');

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(6)
        ->and(StockMovement::acrossBranches()->sole()->quantity_change)->toBe(-4);
});

it('starts a variant that has never been stocked from zero', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();

    adjust()->handle($warehouse, $variant, 5, 'Stok awal');

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(5);
});

it('refuses an adjustment that would take the figure below zero', function () {
    $warehouse = Warehouse::factory()->default()->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity(2)->create();

    expect(fn () => adjust()->handle($warehouse, $variant, -3, 'Salah hitung'))
        ->toThrow(InsufficientStockException::class);

    expect(Stock::acrossBranches()->sole()->quantity)->toBe(2)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});
