<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\RefundMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\TransactionReport;
use Carbon\CarbonImmutable;

function novemberTransactions(?int $branchId = null): ReportFilters
{
    return new ReportFilters(
        CarbonImmutable::parse('2026-11-01')->startOfDay(),
        CarbonImmutable::parse('2026-11-30')->endOfDay(),
        $branchId,
    );
}

it('splits payment attempts per method by outcome', function () {
    $va = PaymentMethod::factory()->create(['name' => 'VA BCA', 'type' => PaymentMethodType::VirtualAccount]);
    $qris = PaymentMethod::factory()->create(['name' => 'QRIS', 'type' => PaymentMethodType::Qris]);
    $order = Order::factory()->create(['grand_total' => 100_000]);

    // One customer lets a VA lapse, then pays by QRIS: two attempts, one success.
    Payment::factory()->forOrder($order)->forMethod($va)->expired()->create(['created_at' => '2026-11-05 10:00:00']);
    Payment::factory()->forOrder($order)->forMethod($qris)->success()->create(['created_at' => '2026-11-05 11:00:00']);
    Payment::factory()->forOrder($order)->forMethod($va)->success()->create(['created_at' => '2026-11-06 11:00:00', 'amount' => 250_000]);
    Payment::factory()->forOrder($order)->forMethod($va)->create(['created_at' => '2026-11-07 11:00:00']);
    // Outside the period.
    Payment::factory()->forOrder($order)->forMethod($va)->success()->create(['created_at' => '2026-10-30 11:00:00']);

    $methods = collect((new TransactionReport(novemberTransactions()))->byMethod())->keyBy('method_name');

    expect($methods['VA BCA'])->toMatchArray([
        'attempts' => 3,
        'success_count' => 1,
        'success_amount' => 250_000,
        'pending_count' => 1,
        'failed_count' => 1,
        'success_rate' => 0.3333,
    ])->and($methods['QRIS'])->toMatchArray([
        'attempts' => 1,
        'success_count' => 1,
        'success_amount' => 100_000,
        'success_rate' => 1.0,
    ]);
});

it('counts orders placed in the period per status, every status listed', function () {
    $branch = Branch::factory()->create();
    Order::factory()->forBranch($branch)->paid()->create(['placed_at' => '2026-11-02 10:00:00', 'grand_total' => 50_000]);
    Order::factory()->forBranch($branch)->paid()->create(['placed_at' => '2026-11-03 10:00:00', 'grand_total' => 70_000]);
    Order::factory()->forBranch($branch)->create(['placed_at' => '2026-11-04 10:00:00', 'grand_total' => 10_000]);
    Order::factory()->forBranch($branch)->paid()->create(['placed_at' => '2026-12-01 10:00:00']);

    $orders = collect((new TransactionReport(novemberTransactions()))->ordersByStatus())->keyBy('status');

    expect($orders[OrderStatus::Paid->value])->toMatchArray(['count' => 2, 'amount' => 120_000])
        ->and($orders[OrderStatus::PendingPayment->value])->toMatchArray(['count' => 1, 'amount' => 10_000])
        ->and($orders[OrderStatus::Shipped->value])->toMatchArray(['count' => 0, 'amount' => 0])
        ->and($orders->has(OrderStatus::PartiallyRefunded->value))->toBeFalse();
});

it('reports refunds per method and status and sums only processed ones as returned money', function () {
    $order = Order::factory()->create();
    Refund::factory()->forOrder($order)->amount(30_000)->processed()->create(['created_at' => '2026-11-10 10:00:00']);
    Refund::factory()->forOrder($order)->amount(20_000)->create(['created_at' => '2026-11-11 10:00:00']);
    Refund::factory()->forOrder($order)->amount(5_000)->method(RefundMethod::Cash)->processed()->create(['created_at' => '2026-11-12 10:00:00']);

    $report = new TransactionReport(novemberTransactions());

    expect($report->summary()['refunded_amount'])->toBe(35_000)
        ->and($report->refunds())->toHaveCount(3);
});
