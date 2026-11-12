<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\ExpirePayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\MidtransApiException;
use App\Jobs\ExpireStockReservations;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
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

// The window's length is `config/shop.php`'s to decide, and nothing here
// depends on what it is. Pinning it keeps the arithmetic below readable.
beforeEach(fn () => config()->set('shop.reservation_minutes', 15));

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

it('releases an expired hold left behind by an order that moved on', function () {
    // Should never happen — every status that gives stock back releases its
    // holds on the way through `TransitionOrderStatus` — but a hold nobody
    // will ever release is the leak this job exists to prevent, so the case is
    // swept rather than trusted. It is a query of its own precisely so that it
    // cannot reach a hold whose order is still waiting to be paid (HIGH-03).
    [$order] = orderWithHold(10, 4);
    StockReservation::acrossBranches()->update(['expires_at' => now()->subMinute()]);
    Order::acrossBranches()->whereKey($order->getKey())->update(['status' => OrderStatus::Processing]);

    sweepReservations();

    expect(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released)
        ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
        ->and(Order::acrossBranches()->sole()->status)->toBe(OrderStatus::Processing);
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

describe('a backlog bigger than one batch (HIGH-03)', function () {
    /**
     * More expired holds than `BATCH_SIZE`, each on its own unpaid order.
     *
     * Written straight to the tables rather than through `ReserveStock`: two
     * hundred and one real reservations would be two hundred and one
     * transactions, and what is under test is which rows the sweep's queries
     * pick, not how the holds were taken.
     *
     * @return array{0: int, 1: Stock}
     */
    function backlogOfUnpaidOrders(int $count): array
    {
        $warehouse = Warehouse::factory()->default()->create();
        /** @var ProductVariant $variant */
        $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();

        $stock = Stock::factory()
            ->inWarehouse($warehouse)
            ->forVariant($variant)
            ->quantity($count * 2)
            ->reserved($count)
            ->create();

        foreach (range(1, $count) as $ignored) {
            $order = Order::factory()->forBranch($warehouse->branch)->create();

            StockReservation::factory()
                ->inWarehouse($warehouse)
                ->forVariant($variant)
                ->forOrder($order)
                ->quantity(1)
                ->expired()
                ->create();
        }

        return [$count, $stock];
    }

    it('never strips a hold from an order it has not expired', function () {
        // The bug: `expireOrders()` finished the first 200 orders, and the
        // orphan sweep then picked up holds 201 onwards — holds belonging to
        // orders still sitting in `pending_payment`. Their stock went back on
        // sale while the customer was still shown an order they could pay for,
        // and paying it would have committed nothing at all.
        [$total] = backlogOfUnpaidOrders(ExpireStockReservations::BATCH_SIZE + 5);

        sweepReservations();

        $stranded = Order::acrossBranches()
            ->where('status', OrderStatus::PendingPayment)
            ->whereDoesntHave('stockReservations', fn ($holds) => $holds->active())
            ->count();

        expect($stranded)->toBe(0);

        $expired = Order::acrossBranches()->where('status', OrderStatus::Expired)->count();
        $stillPending = Order::acrossBranches()->where('status', OrderStatus::PendingPayment)->count();

        expect($expired)->toBe(ExpireStockReservations::BATCH_SIZE)
            ->and($stillPending)->toBe($total - ExpireStockReservations::BATCH_SIZE)
            ->and((int) Stock::acrossBranches()->sole()->reserved_quantity)->toBe($stillPending);
    });

    it('works the rest of the backlog off on the next run', function () {
        [$total] = backlogOfUnpaidOrders(ExpireStockReservations::BATCH_SIZE + 5);

        sweepReservations();
        sweepReservations();

        expect(Order::acrossBranches()->where('status', OrderStatus::Expired)->count())->toBe($total)
            ->and(Order::acrossBranches()->where('status', OrderStatus::PendingPayment)->count())->toBe(0)
            ->and((int) Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
    });
});

describe('the payment attempts it closes with them (P2-E1-T3, ADR-041)', function () {
    it('closes the order payment attempt as part of expiring the order', function () {
        [$order] = orderWithHold();
        $payment = Payment::factory()->forOrder($order)->create();

        sweepReservations(Carbon::now()->addMinutes(16));

        expect($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($payment->fresh()->failure_reason)->toBe(ExpirePayment::DEADLINE_PASSED);
    });

    it('sweeps an overdue attempt whose order is no longer waiting on it', function () {
        // A COD order has no hold ticking down, so nothing above would reach
        // its attempt; an attempt with a deadline of its own still has to be
        // closed by somebody.
        $order = Order::factory()->paid()->create();
        $payment = Payment::factory()->forOrder($order)->overrun()->create();

        sweepReservations();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired);
    });

    it('leaves an attempt that is still within its window alone', function () {
        [$order] = orderWithHold();
        $payment = Payment::factory()->forOrder($order)->create();

        sweepReservations();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    });

    it('never closes an attempt whose order is still payable', function () {
        [$order] = orderWithHold();
        $payment = Payment::factory()->forOrder($order)->expiringAt(now()->addHour())->create();

        sweepReservations();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($payment->fresh()->isOpen())->toBeTrue();
    });
});

describe('asking the provider before giving up on an order (ADR-052)', function () {
    beforeEach(function () {
        config()->set('services.midtrans.server_key', 'SB-Mid-server-FAKEKEY');
        $this->midtrans = fakeMidtrans();
    });

    /**
     * An unpaid order holding four units, with an open Midtrans attempt that
     * shares the hold's fifteen-minute deadline.
     *
     * @return array{0: Order, 1: Payment}
     */
    function orderAwaitingMidtrans(): array
    {
        [$order] = orderWithHold();

        $payment = Payment::factory()
            ->forOrder($order)
            ->forMethod(PaymentMethod::factory()->midtrans()->create())
            ->expiringAt(now()->addMinutes(15))
            ->create();

        return [$order, $payment];
    }

    it('settles an order that was paid at Midtrans instead of expiring it', function () {
        $this->freezeTime();
        [$order, $payment] = orderAwaitingMidtrans();
        $this->midtrans->statusAnswers(midtransStatusAnswer($payment));

        sweepReservations(Carbon::now()->addMinutes(16));

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Committed);
    });

    it('expires the order when Midtrans says the payment is still pending', function () {
        $this->freezeTime();
        [$order, $payment] = orderAwaitingMidtrans();
        $this->midtrans->statusAnswers(midtransStatusAnswer($payment, 'pending'));

        sweepReservations(Carbon::now()->addMinutes(16));

        expect($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
    });

    it('keeps the order and its stock while Midtrans cannot be reached', function () {
        $this->freezeTime();
        [$order, $payment] = orderAwaitingMidtrans();
        $this->midtrans->statusAnswers(MidtransApiException::unreachable());

        sweepReservations(Carbon::now()->addMinutes(16));

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(4);
    });

    it('stops waiting for an unreachable Midtrans once the grace period is over', function () {
        $this->freezeTime();
        [$order, $payment] = orderAwaitingMidtrans();
        $this->midtrans->statusAnswers(MidtransApiException::unreachable());

        sweepReservations(Carbon::now()->addMinutes(15 + ExpireStockReservations::GATEWAY_GRACE_MINUTES));

        expect($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
    });

    it('settles an overdue paid attempt whose order hold has not run out', function () {
        $this->freezeTime();
        [$order] = orderWithHold();
        $payment = Payment::factory()
            ->forOrder($order)
            ->forMethod(PaymentMethod::factory()->midtrans()->create())
            ->overrun()
            ->create();
        $this->midtrans->statusAnswers(midtransStatusAnswer($payment));

        sweepReservations();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});
