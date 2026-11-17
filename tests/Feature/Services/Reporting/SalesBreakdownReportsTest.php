<?php

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Branch\BranchContext;
use App\Services\Reporting\BestSellerReport;
use App\Services\Reporting\BranchPerformanceReport;
use App\Services\Reporting\CustomerReport;
use App\Services\Reporting\ReportFilters;
use Carbon\CarbonImmutable;

function novemberBreakdown(?int $branchId = null): ReportFilters
{
    return new ReportFilters(
        CarbonImmutable::parse('2026-11-01')->startOfDay(),
        CarbonImmutable::parse('2026-11-30')->endOfDay(),
        $branchId,
    );
}

it('ranks variants by units sold, grouped by the SKU on the line', function () {
    $branch = Branch::factory()->create();

    reportSale($branch, '2026-11-02 10:00:00', [[10_000, 1, 0, 0, 'sku' => 'KAOS-M'], [50_000, 1, 0, 0, 'sku' => 'JAKET-L']]);
    reportSale($branch, '2026-11-03 10:00:00', [[10_000, 4, 0, 1, 'sku' => 'KAOS-M']]);

    $rows = (new BestSellerReport(novemberBreakdown()))->top(5);

    expect($rows[0])->toMatchArray([
        'rank' => 1, 'sku' => 'KAOS-M', 'orders' => 2, 'units' => 5, 'returned_units' => 1,
        'gross' => 50_000, 'net' => 40_000,
    ])->and($rows[1])->toMatchArray(['rank' => 2, 'sku' => 'JAKET-L', 'units' => 1]);
});

it('sums each customer spend from their counted orders and counts first-time buyers', function () {
    $branch = Branch::factory()->create();
    $loyal = User::factory()->customer()->create();
    $newcomer = User::factory()->customer()->create();

    // The loyal customer first bought in October, so November does not make them new.
    reportSale($branch, '2026-10-15 10:00:00', [[10_000, 1]], ['user_id' => $loyal->id]);
    reportSale($branch, '2026-11-02 10:00:00', [[20_000, 1]], ['user_id' => $loyal->id, 'shipping_cost' => 5_000]);
    reportSale($branch, '2026-11-09 10:00:00', [[30_000, 1]], ['user_id' => $loyal->id]);
    reportSale($branch, '2026-11-20 10:00:00', [[40_000, 1]], ['user_id' => $newcomer->id]);
    reportSale($branch, '2026-11-21 10:00:00', [[99_000, 1]], ['user_id' => null]);

    $report = new CustomerReport(novemberBreakdown());
    $rows = collect($report->paginate()->items())->keyBy('user_id');

    expect($rows[$loyal->id])->toMatchArray(['orders' => 2, 'spent' => 55_000, 'average_order' => 27_500])
        ->and($rows[$newcomer->id])->toMatchArray(['orders' => 1, 'spent' => 40_000])
        ->and($report->summary())->toBe([
            'customers' => 2,
            'new_customers' => 1,
            'returning_customers' => 1,
            'spent' => 95_000,
            'average_spent' => 47_500,
            'guest_orders' => 1,
        ]);
});

it('lists every branch with its sales, share and cancellations, idle branches included', function () {
    $jakarta = Branch::factory()->create(['name' => 'Jakarta']);
    $bandung = Branch::factory()->create(['name' => 'Bandung']);
    $idle = Branch::factory()->create(['name' => 'Surabaya']);

    reportSale($jakarta, '2026-11-02 10:00:00', [[60_000, 1]]);
    reportSale($jakarta, '2026-11-03 10:00:00', [[90_000, 1]]);
    reportSale($bandung, '2026-11-04 10:00:00', [[50_000, 1]]);
    Order::factory()->forBranch($bandung)->create([
        'status' => OrderStatus::Cancelled, 'cancelled_at' => '2026-11-05 10:00:00',
    ]);

    $rows = collect((new BranchPerformanceReport(novemberBreakdown()))->rows())->keyBy('branch_id');

    expect($rows[$jakarta->id])->toMatchArray(['orders' => 2, 'net' => 150_000, 'average_order' => 75_000, 'share' => 0.75])
        ->and($rows[$bandung->id])->toMatchArray(['orders' => 1, 'net' => 50_000, 'cancelled' => 1, 'share' => 0.25])
        ->and($rows[$idle->id])->toMatchArray(['orders' => 0, 'net' => 0, 'share' => 0.0])
        ->and($rows->keys()->first())->toBe($jakarta->id);
});

it('shows a branch account only its own branch row', function () {
    $mine = Branch::factory()->create();
    Branch::factory()->create();

    $rows = app(BranchContext::class)->forBranch(
        $mine->id,
        fn (): array => (new BranchPerformanceReport(novemberBreakdown()))->rows(),
    );

    expect(array_column($rows, 'branch_id'))->toBe([$mine->id]);
});
