<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\ReconcilePayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\MidtransApiException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;

/*
 * Asking the provider what became of a payment, and applying the answer
 * (P2-E1-T6, ADR-052, ADR-053).
 *
 * There is no webhook, so this is the only way a gateway payment is ever
 * settled — which makes what it refuses matter as much as what it applies: a
 * part payment, an answer for an attempt that has already finished, and a
 * provider that could not be asked at all.
 */

beforeEach(function () {
    config()->set('services.midtrans.server_key', 'SB-Mid-server-RECONCILE');

    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->method = PaymentMethod::factory()->midtrans(PaymentMethodType::VirtualAccount)->create();
    $this->midtrans = fakeMidtrans();
});

/**
 * An unpaid order holding stock, with a pending Midtrans attempt against it.
 *
 * @return array{0: Order, 1: Payment}
 */
function orderAwaitingGateway(int $held = 2, int $onHand = 10, ?PaymentMethod $method = null): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch(test()->branch)->create([
        'subtotal' => 500_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => 500_000,
        'payment_status' => PaymentStatus::Pending,
    ]);

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    $payment = Payment::factory()
        ->forOrder($order->fresh())
        ->forMethod($method ?? test()->method)
        ->create(['external_id' => null]);

    return [$order->fresh(), $payment];
}

/**
 * Have Midtrans answer `$status` for the attempt, then ask it.
 *
 * Returns whether the provider could be asked.
 *
 * @param  array<string, mixed>  $overrides
 */
function reconcile(Payment $payment, string $status = 'settlement', array $overrides = []): bool
{
    test()->midtrans->statusAnswers([...midtransStatusAnswer($payment, $status), ...$overrides]);

    return app(ReconcilePayment::class)->handle($payment->fresh());
}

describe('a settlement Midtrans confirms', function () {
    it('marks the payment and the order paid, and commits the stock', function () {
        [$order, $payment] = orderAwaitingGateway(held: 2, onHand: 10);

        reconcile($payment);

        $order->refresh();
        $stock = Stock::acrossBranches()->sole();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($payment->fresh()->external_id)->toBe('mt-status-1')
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Committed)
            ->and($stock->quantity)->toBe(8)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('keeps the payload so a dispute can be read in the gateway own words', function () {
        [, $payment] = orderAwaitingGateway();

        reconcile($payment);

        expect($payment->fresh()->raw_payload)
            ->toHaveKey('transaction_status')
            ->and($payment->fresh()->raw_payload['transaction_status'])->toBe('settlement');
    });

    it('does not commit the stock twice when it is asked about again', function () {
        [, $payment] = orderAwaitingGateway(held: 2, onHand: 10);

        reconcile($payment);
        reconcile($payment);

        expect(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(8);
    });
});

describe('what it refuses', function () {
    it('does not settle an order on a payment for the wrong amount', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment, overrides: ['gross_amount' => '1000.00']);

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);
    });

    it('still keeps the answer about a mismatched amount, because that is the evidence', function () {
        [, $payment] = orderAwaitingGateway();

        reconcile($payment, overrides: ['gross_amount' => '1000.00']);

        expect($payment->fresh()->raw_payload['gross_amount'])->toBe('1000.00');
    });

    it('reports that nobody could say, and changes nothing, when Midtrans cannot be reached', function () {
        [$order, $payment] = orderAwaitingGateway();
        $this->midtrans->statusAnswers(MidtransApiException::unreachable());

        $answered = app(ReconcilePayment::class)->handle($payment);

        expect($answered)->toBeFalse()
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('has nobody to ask about a manual payment, and leaves it for staff', function () {
        [$order, $payment] = orderAwaitingGateway(method: PaymentMethod::factory()->create());

        $answered = app(ReconcilePayment::class)->handle($payment);

        expect($answered)->toBeTrue()
            ->and($this->midtrans->statusChecks)->toBe([])
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });
});

describe('the other outcomes', function () {
    it('closes a denied transaction and leaves the order payable', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment, 'deny');

        $order->refresh();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Failed)
            ->and($order->isPayable())->toBeTrue()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Active);
    });

    it('expires a lapsed virtual account', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment, 'expire');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Expired);
    });

    it('records a refund the gateway reports, without touching the order lines', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment, 'refund');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Refunded)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
    });

    it('writes nothing while Midtrans still says pending', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment, 'pending');

        // The order page asks on every poll; rewriting the row each time
        // would bury the charge answer the customer is still paying against.
        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($payment->fresh()->raw_payload)->toBe($payment->raw_payload)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('changes nothing when a settled attempt is asked about again', function () {
        [, $payment] = orderAwaitingGateway();

        reconcile($payment);
        $settledAt = $payment->fresh()->paid_at;

        reconcile($payment);

        expect($payment->fresh()->paid_at?->toIso8601String())
            ->toBe($settledAt?->toIso8601String());
    });

    it('does not undo a settled payment on a later deny', function () {
        [$order, $payment] = orderAwaitingGateway();

        reconcile($payment);
        reconcile($payment, 'deny');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('does not settle an order whose stock has gone back on sale', function () {
        [$order, $payment] = orderAwaitingGateway();

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Expired);

        // The transition closed the attempt with the order, so there is no
        // open attempt left to ask about, and nothing is marked paid.
        reconcile($payment->fresh());

        expect($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(0);
    });
});
