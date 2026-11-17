<?php

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

function novemberSales(array $overrides = []): ReportFilters
{
    return new ReportFilters(
        from: CarbonImmutable::parse('2026-11-01')->startOfDay(),
        until: CarbonImmutable::parse('2026-11-30')->endOfDay(),
        branchId: $overrides['branchId'] ?? null,
        categoryId: $overrides['categoryId'] ?? null,
        group: $overrides['group'] ?? ReportFilters::GROUP_DAY,
    );
}

it('adds up only paid, uncancelled orders paid inside the period', function () {
    $branch = Branch::factory()->create();

    // Counted: 2 × 50.000 − 10.000 discount, and 1 × 30.000 with 5.000 shipping.
    reportSale($branch, '2026-11-03 10:00:00', [[50_000, 2, 10_000]]);
    reportSale($branch, '2026-11-20 23:59:00', [[30_000, 1]], ['shipping_cost' => 5_000]);

    // Not counted: paid then cancelled, paid outside the period, never paid.
    reportSale($branch, '2026-11-05 09:00:00', [[999_000, 1]], [
        'status' => OrderStatus::Cancelled, 'cancelled_at' => '2026-11-06 09:00:00',
    ]);
    reportSale($branch, '2026-10-31 23:59:59', [[999_000, 1]]);
    reportSale($branch, '2026-12-01 00:00:00', [[999_000, 1]]);
    Order::factory()->forBranch($branch)->create(['placed_at' => '2026-11-10 10:00:00']);

    expect((new SalesReport(novemberSales()))->summary())->toBe([
        'gross' => 120_000,
        'discount' => 10_000,
        'returned' => 0,
        'net' => 120_000,
        'orders' => 2,
        'units' => 3,
        'average_order' => 60_000,
        'shipping' => 5_000,
    ]);
});

it('states returns beside the sale instead of rewriting it', function () {
    $branch = Branch::factory()->create();

    // 3 units at 40.000 with 12.000 discount on the line: subtotal 108.000.
    // One unit came back, worth a third of the subtotal: 36.000.
    reportSale($branch, '2026-11-10 12:00:00', [[40_000, 3, 12_000, 1]], [
        'status' => OrderStatus::Refunded,
    ]);

    $summary = (new SalesReport(novemberSales()))->summary();

    expect($summary['gross'])->toBe(108_000)
        ->and($summary['returned'])->toBe(36_000)
        ->and($summary['net'])->toBe(72_000)
        ->and($summary['orders'])->toBe(1);
});

it('rounds the value of returned units down', function () {
    // 100.000 split over 3 units: one returned unit is worth 33.333.
    reportSale(Branch::factory()->create(), '2026-11-10 12:00:00', [[100_000, 3, 200_000, 1]]);

    expect((new SalesReport(novemberSales()))->summary()['returned'])->toBe(33_333);
});

it('keeps one branch out of another branch filter', function () {
    $jakarta = Branch::factory()->create();
    $bandung = Branch::factory()->create();

    reportSale($jakarta, '2026-11-02 10:00:00', [[10_000, 1]]);
    reportSale($bandung, '2026-11-02 10:00:00', [[70_000, 1]]);

    expect((new SalesReport(novemberSales(['branchId' => $bandung->id])))->summary()['net'])->toBe(70_000);
});

it('narrows to a category and everything beneath it', function () {
    $branch = Branch::factory()->create();
    $electronics = Category::factory()->create();
    $audio = Category::factory()->childOf($electronics)->create();
    $books = Category::factory()->create();

    $headphones = Product::factory()->for($audio)->create();
    $novel = Product::factory()->for($books)->create();

    // One basket with both: only the audio line belongs to Elektronik.
    reportSale($branch, '2026-11-04 10:00:00', [
        [200_000, 1, 0, 0, 'product' => $headphones],
        [80_000, 2, 0, 0, 'product' => $novel],
    ], ['shipping_cost' => 15_000]);

    $summary = (new SalesReport(novemberSales(['categoryId' => $electronics->id])))->summary();

    expect($summary['gross'])->toBe(200_000)
        ->and($summary['units'])->toBe(1)
        ->and($summary['orders'])->toBe(1)
        // A parcel cannot be split between categories.
        ->and($summary['shipping'])->toBeNull();
});

it('splits sales per category, putting lines of deleted products under no category', function () {
    $branch = Branch::factory()->create();
    $books = Category::factory()->create(['name' => 'Buku']);
    $novel = Product::factory()->for($books)->create();

    reportSale($branch, '2026-11-04 10:00:00', [
        [80_000, 2, 0, 0, 'product' => $novel],
        [5_000, 1],
    ]);

    $rows = collect((new SalesReport(novemberSales()))->byCategory());

    expect($rows->firstWhere('category_id', $books->id))->toMatchArray(['units' => 2, 'net' => 160_000])
        ->and($rows->firstWhere('category_id', null))->toMatchArray([
            'category_name' => 'Tanpa kategori', 'units' => 1, 'net' => 5_000,
        ]);
});

it('gives every bucket of the period a point, quiet days included', function () {
    $branch = Branch::factory()->create();

    reportSale($branch, '2026-11-02 08:00:00', [[10_000, 1]]);
    reportSale($branch, '2026-11-02 20:00:00', [[15_000, 1]]);

    $filters = new ReportFilters(
        CarbonImmutable::parse('2026-11-01')->startOfDay(),
        CarbonImmutable::parse('2026-11-03')->endOfDay(),
    );

    expect(collect((new SalesReport($filters))->series())
        ->map(fn (array $point): array => Arr::only($point, ['key', 'net', 'orders']))
        ->all())->toBe([
            ['key' => '2026-11-01', 'net' => 0, 'orders' => 0],
            ['key' => '2026-11-02', 'net' => 25_000, 'orders' => 2],
            ['key' => '2026-11-03', 'net' => 0, 'orders' => 0],
        ]);
});

it('folds days into weeks starting on Monday and into months', function (string $group, array $expected) {
    $branch = Branch::factory()->create();

    reportSale($branch, '2026-11-01 10:00:00', [[10_000, 1]]); // Sunday
    reportSale($branch, '2026-11-02 10:00:00', [[20_000, 1]]); // Monday
    reportSale($branch, '2026-11-30 10:00:00', [[40_000, 1]]); // Monday

    $series = (new SalesReport(novemberSales(['group' => $group])))->series();

    expect(collect($series)->pluck('net', 'key')->all())->toBe($expected);
})->with([
    'weeks' => ['week', [
        '2026-10-26' => 10_000,
        '2026-11-02' => 20_000,
        '2026-11-09' => 0,
        '2026-11-16' => 0,
        '2026-11-23' => 0,
        '2026-11-30' => 40_000,
    ]],
    'months' => ['month', ['2026-11' => 70_000]],
]);

it('writes the same figures to its export table, with a total row', function () {
    reportSale(Branch::factory()->create(), '2026-11-02 10:00:00', [[10_000, 2]]);

    $table = (new SalesReport(novemberSales(['group' => 'month'])))->table();

    expect(iterator_to_array($table->rows))->toBe([[
        'label' => 'November 2026', 'orders' => 1, 'units' => 2,
        'gross' => 20_000, 'returned' => 0, 'net' => 20_000,
    ]])->and($table->totals['net'])->toBe(20_000);
});
