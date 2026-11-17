<?php

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\StockCardReport;
use App\Services\Reporting\StockPositionReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function stockMove(Warehouse $warehouse, ProductVariant $variant, StockMovementType $type, int $change, string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at));

    DB::transaction(fn () => app(StockLedger::class)->apply($warehouse, $variant, $type, $change));

    test()->travelBack();
}

it('works the stock card out from the ledger: opening, in, out, sold, closing', function () {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    stockMove($warehouse, $variant, StockMovementType::EntryIn, 100, '2026-10-20 10:00:00'); // before: opening 100
    stockMove($warehouse, $variant, StockMovementType::SaleOut, -30, '2026-11-05 10:00:00');
    stockMove($warehouse, $variant, StockMovementType::EntryIn, 20, '2026-11-10 10:00:00');
    stockMove($warehouse, $variant, StockMovementType::IssueOut, -5, '2026-11-15 10:00:00');
    stockMove($warehouse, $variant, StockMovementType::SaleOut, -10, '2026-12-02 10:00:00'); // after the period

    $filters = new ReportFilters(
        CarbonImmutable::parse('2026-11-01')->startOfDay(),
        CarbonImmutable::parse('2026-11-30')->endOfDay(),
    );
    $report = new StockCardReport($filters);

    expect($report->paginate()->items()[0])->toMatchArray([
        'opening' => 100,
        'in' => 20,
        'out' => 35,
        'sold' => 30,
        'closing' => 85,
    ])->and($report->summary())->toBe([
        'opening' => 100, 'in' => 20, 'out' => 35, 'sold' => 30, 'closing' => 85,
    ])->and(Stock::query()->sole()->quantity)->toBe(75);
});

it('leaves out pairs that neither moved in the period nor held anything at its end', function () {
    $warehouse = Warehouse::factory()->create();
    $emptied = ProductVariant::factory()->create();
    $held = ProductVariant::factory()->create();

    stockMove($warehouse, $emptied, StockMovementType::EntryIn, 5, '2026-09-01 10:00:00');
    stockMove($warehouse, $emptied, StockMovementType::SaleOut, -5, '2026-09-02 10:00:00');
    stockMove($warehouse, $held, StockMovementType::EntryIn, 7, '2026-09-01 10:00:00');

    $filters = new ReportFilters(
        CarbonImmutable::parse('2026-11-01')->startOfDay(),
        CarbonImmutable::parse('2026-11-30')->endOfDay(),
    );

    expect(collect((new StockCardReport($filters))->paginate()->items())->pluck('product_variant_id')->all())
        ->toBe([$held->id]);
});

it('values stock at the variant cost, falling back to the product cost', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create(['cost_price' => 4_000]);
    $own = ProductVariant::factory()->for($product)->create(['cost_price' => 10_000]);
    $inherits = ProductVariant::factory()->for($product)->create(['cost_price' => null]);

    Stock::factory()->inWarehouse($warehouse)->forVariant($own)->quantity(3)->reserved(1)->create();
    Stock::factory()->inWarehouse($warehouse)->forVariant($inherits)->quantity(5)->create();

    expect((new StockPositionReport(ReportFilters::lastDays()))->summary())->toMatchArray([
        'units' => 8,
        'reserved' => 1,
        'value' => 3 * 10_000 + 5 * 4_000,
    ]);
});

it('narrows the position to low or empty stock', function (string $state, array $expected) {
    $warehouse = Warehouse::factory()->create();

    foreach (['ok' => [50, 10], 'low' => [4, 10], 'out' => [0, 0]] as $sku => [$quantity, $minimum]) {
        Stock::factory()->inWarehouse($warehouse)
            ->forVariant(ProductVariant::factory()->create(['sku' => mb_strtoupper("SKU-{$sku}")]))
            ->quantity($quantity)->minimum($minimum)->create();
    }

    $filters = new ReportFilters(
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->endOfDay(),
        state: $state,
    );

    expect(collect((new StockPositionReport($filters))->paginate()->items())->pluck('sku')->sort()->values()->all())
        ->toBe($expected);
})->with([
    'low' => ['low', ['SKU-LOW']],
    'out' => ['out', ['SKU-OUT']],
]);

it('keeps another branch out of a branch filter', function () {
    $mine = Warehouse::factory()->for(Branch::factory())->create();
    $theirs = Warehouse::factory()->for(Branch::factory())->create();
    Stock::factory()->inWarehouse($mine)->quantity(3)->create();
    Stock::factory()->inWarehouse($theirs)->quantity(9)->create();

    $filters = ReportFilters::lastDays()->forBranch($mine->branch_id);

    expect((new StockPositionReport($filters))->summary()['units'])->toBe(3);
});
