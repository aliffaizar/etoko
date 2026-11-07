<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Jobs\ExpireStockReservations;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/*
 * Giving back stock nobody finished paying for (P1-E7-T3, BR-6).
 *
 * Without this sweep one abandoned basket would hold its units for ever:
 * `reserved_quantity` would climb and never come down, and a branch with stock
 * on the shelf would gradually stop being able to sell it.
 */

function sweepReservations(?Carbon $asOf = null): void
{
    app()->call([new ExpireStockReservations($asOf), 'handle']);
}

/**
 * An unpaid order holding stock, with a hold that expires in fifteen minutes.
 *
 * @return array{0: Order, 1: ProductVariant}
 */
function orderWithHold(int $onHand = 10, int $held = 4): array
{
    $warehouse = Warehouse::factory()->default()->create();
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch($warehouse->branch)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    return [$order->fresh(), $variant];
}

it('leaves a hold that is still within its window alone', function () {
    [$order] = orderWithHold();

    sweepReservations();

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(4)
        ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Active);
});

it('expires the order and gives its stock back once the window has passed', function () {
    [$order] = orderWithHold(10, 4);

    sweepReservations(Carbon::now()->addMinutes(16));

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Expired)
        ->and($order->payment_status)->toBe(PaymentStatus::Expired)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(10)
        ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
});

it('records the expiry on the timeline', function () {
    [$order] = orderWithHold();

    sweepReservations(Carbon::now()->addMinutes(16));

    $history = $order->statusHistories()->sole();

    expect($history->to_status)->toBe(OrderStatus::Expired)
        ->and($history->changed_by)->toBeNull()
        ->and($history->note)->toBe('Batas waktu pembayaran terlewat.');
});

it('makes the stock buyable again', function () {
    [$order, $variant] = orderWithHold(4, 4);

    expect(Stock::acrossBranches()->sole()->available_quantity)->toBe(0);

    sweepReservations(Carbon::now()->addMinutes(16));

    expect(Stock::acrossBranches()->sole()->available_quantity)->toBe(4);
});

it('leaves an order that was paid for in the meantime alone', function () {
    [$order] = orderWithHold(10, 4);
    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);

    sweepReservations(Carbon::now()->addMinutes(16));

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(6)
        ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Committed);
});

it('leaves an order that was already cancelled alone', function () {
    [$order] = orderWithHold(10, 4);
    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled);

    sweepReservations(Carbon::now()->addMinutes(16));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->statusHistories()->count())->toBe(1);
});

it('releases an expired hold that belongs to no order', function () {
    $warehouse = Warehouse::factory()->default()->create();
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)
        ->quantity(10)->reserved(3)->create();

    $hold = StockReservation::factory()
        ->inWarehouse($warehouse)
        ->forVariant($variant)
        ->quantity(3)
        ->expired()
        ->create(['order_id' => null]);

    sweepReservations();

    expect($hold->fresh()->status)->toBe(ReservationStatus::Released)
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
});

it('never lowers a figure twice when run again', function () {
    [$order] = orderWithHold(10, 4);
    $moment = Carbon::now()->addMinutes(16);

    sweepReservations($moment);
    sweepReservations($moment);
    sweepReservations($moment);

    expect(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
        ->and(Stock::acrossBranches()->sole()->quantity)->toBe(10)
        ->and($order->fresh()->statusHistories()->count())->toBe(1);
});

it('sweeps every expired order, not just the first', function () {
    $orders = collect(range(1, 3))->map(fn (): Order => orderWithHold(10, 2)[0]);

    sweepReservations(Carbon::now()->addMinutes(16));

    expect($orders->every(fn (Order $order): bool => $order->fresh()->status === OrderStatus::Expired))
        ->toBeTrue()
        ->and((int) Stock::acrossBranches()->sum('reserved_quantity'))->toBe(0);
});

it('is scheduled to run every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->description, 'ExpireStockReservations')
            || $event->getSummaryForDisplay() === 'expire-stock-reservations');

    expect($events)->not->toBeEmpty()
        ->and($events->first()->expression)->toBe('* * * * *');
});
