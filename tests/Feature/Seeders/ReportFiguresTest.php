<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Stock;
use App\Services\Reporting\BestSellerReport;
use App\Services\Reporting\BranchPerformanceReport;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\SalesReport;
use App\Services\Reporting\StockCardReport;
use App\Services\Reporting\StockPositionReport;
use App\Services\Reporting\TransactionReport;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\OrderDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;

/*
 * The reports against the demo data (P2-E7-T10).
 *
 * The demo orders are placed through `PlaceOrder` and moved through
 * `TransitionOrderStatus`, so they are the closest thing to real trade the
 * suite has. Every figure below is worked out a second time here, row by row
 * in PHP from the models, and the report's SQL must arrive at the same
 * number — the two share nothing but the definition of a sale (ADR-070).
 */

beforeEach(function () {
    config()->set('services.midtrans.server_key', 'SB-Mid-server-DEMOKEY');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoDataSeeder::class);
    $this->seed(CatalogDemoSeeder::class);
    $this->seed(InventoryDemoSeeder::class);
    $this->seed(OrderDemoSeeder::class);
});

/**
 * @return Collection<int, Order>
 */
function seededSales(): Collection
{
    return Order::acrossBranches()
        ->with('items')
        ->get()
        ->filter(fn (Order $order): bool => $order->paid_at !== null && $order->status !== OrderStatus::Cancelled)
        ->values();
}

it('adds up the seeded sales to the rupiah', function () {
    $sales = seededSales();
    $lines = $sales->flatMap->items;

    expect($sales)->not->toBeEmpty();

    $returned = $lines->sum(fn (OrderItem $line): int => intdiv($line->subtotal * $line->refunded_quantity, $line->quantity));

    expect((new SalesReport(ReportFilters::lastDays(365)))->summary())->toMatchArray([
        'gross' => $lines->sum('subtotal'),
        'returned' => $returned,
        'net' => $lines->sum('subtotal') - $returned,
        'orders' => $sales->count(),
        'units' => $lines->sum('quantity'),
        'shipping' => $sales->sum('shipping_cost'),
    ]);
});

it('splits the seeded sales between branches without losing or doubling any', function () {
    $filters = ReportFilters::lastDays(365);
    $rows = collect((new BranchPerformanceReport($filters))->rows());
    $expected = seededSales()->groupBy('branch_id')->map(fn ($orders): int => $orders->flatMap->items->sum('subtotal'));

    foreach ($expected as $branchId => $gross) {
        expect($rows->firstWhere('branch_id', $branchId)['gross'])->toBe($gross);
    }

    expect($rows->sum('net'))->toBe((new SalesReport($filters))->summary()['net'])
        ->and(round($rows->sum('share'), 2))->toBe(1.0);
});

it('ranks the seeded best sellers with the units their lines hold', function () {
    $unitsBySku = seededSales()->flatMap->items
        ->groupBy('sku')
        ->map(fn ($lines): int => $lines->sum('quantity'))
        ->sortDesc();

    $top = (new BestSellerReport(ReportFilters::lastDays(365)))->top(1)[0];

    expect($top['units'])->toBe($unitsBySku->first());
});

it('counts every seeded payment attempt once', function () {
    $summary = (new TransactionReport(ReportFilters::lastDays(365)))->summary();

    expect($summary['attempts'])->toBe(Payment::acrossBranches()->count())
        ->and($summary['success_amount'])->toBe((int) Payment::acrossBranches()->where('status', 'success')->sum('amount'));
});

it('agrees with the seeded shelves now and with the ledger over time', function () {
    $filters = ReportFilters::lastDays(365);

    expect((new StockPositionReport($filters))->summary()['units'])->toBe((int) Stock::acrossBranches()->sum('quantity'));

    $card = (new StockCardReport($filters))->summary();

    // A period that ends today closes on today's shelves, and the ledger
    // must explain the difference from its opening.
    expect($card['closing'])->toBe((int) Stock::acrossBranches()->sum('quantity'))
        ->and($card['opening'] + $card['in'] - $card['out'])->toBe($card['closing']);
});
