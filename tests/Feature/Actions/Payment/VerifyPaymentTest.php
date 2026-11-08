<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\ExpirePayment;
use App\Actions\Payment\FailPayment;
use App\Actions\Payment\InitiatePayment;
use App\Actions\Payment\VerifyPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\PaymentFailedException;
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
use App\Models\User;
use App\Models\Warehouse;

/*
 * Recording that the money arrived (P2-E1-T3, T4, T6).
 *
 * The one place money becomes real, and therefore the one place a mistake
 * either sells goods that never left the shelf or loses goods that did.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->staff = User::factory()->adminCabang($this->branch)->create();
    $this->verify = app(VerifyPayment::class);
    $this->initiate = app(InitiatePayment::class);
});

/**
 * An order with a pending manual transfer against it, as `P2-E1-T3` leaves one.
 *
 * Built through the real actions so the holds, the ledger and the timeline are
 * the ones production would produce.
 *
 * @return array{0: Order, 1: Payment}
 */
function orderAwaitingTransfer(int $held = 2, int $onHand = 10, ?PaymentMethod $method = null): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch(test()->branch)->create([
        'subtotal' => 500_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => 500_000,
    ]);

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    $payment = test()->initiate->handle(
        $order->fresh(),
        $method ?? PaymentMethod::factory()->create(),
    );

    return [$order->fresh(), $payment];
}

describe('settling a transfer', function () {
    it('marks the attempt successful and records who said so', function () {
        [, $payment] = orderAwaitingTransfer();

        expect($this->verify->handle($payment, $this->staff))->toBeTrue();

        $payment->refresh();

        expect($payment->status)->toBe(PaymentTransactionStatus::Success)
            ->and($payment->paid_at)->not->toBeNull()
            ->and($payment->verified_by)->toBe($this->staff->getKey())
            ->and($payment->verified_at)->not->toBeNull();
    });

    it('moves the order to paid, which is what commits its stock', function () {
        [$order, $payment] = orderAwaitingTransfer(held: 2, onHand: 10);

        $this->verify->handle($payment, $this->staff);
        $order->refresh();

        $stock = Stock::acrossBranches()->sole();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Committed)
            ->and($stock->quantity)->toBe(8)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('writes the verifier note onto the order timeline', function () {
        [$order, $payment] = orderAwaitingTransfer();

        $this->verify->handle($payment, $this->staff, 'Cek mutasi BCA 08/11 14:02.');

        expect($order->fresh()->statusHistories->last()->note)
            ->toBe('Cek mutasi BCA 08/11 14:02.');
    });

    it('is idempotent: settling twice answers false the second time', function () {
        [, $payment] = orderAwaitingTransfer();

        expect($this->verify->handle($payment, $this->staff))->toBeTrue()
            ->and($this->verify->handle($payment->fresh(), $this->staff))->toBeFalse();

        expect(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('throws from handleOrFail when there is nothing left to settle', function () {
        [, $payment] = orderAwaitingTransfer();
        $this->verify->handle($payment, $this->staff);

        $this->verify->handleOrFail($payment->fresh(), $this->staff);
    })->throws(PaymentFailedException::class, 'tidak dapat diubah');
});

describe('refusing to settle', function () {
    it('does nothing for an expired order, because its attempt went with it', function () {
        [$order, $payment] = orderAwaitingTransfer();

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Expired);

        // The transition closed the attempt (P2-E1-T3), so there is nothing
        // left to settle and the goods that went back on sale stay there.
        expect($this->verify->handle($payment->fresh(), $this->staff))->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(0);
    });

    it('refuses an attempt left open on an order that has finished', function () {
        // The one way this state is reachable: `refunded` is final but does
        // not release stock, so it does not close open attempts. Settling one
        // would mark a refunded order paid.
        $order = Order::factory()->forBranch($this->branch)->create([
            'status' => OrderStatus::Refunded,
            'payment_status' => PaymentStatus::Refunded,
        ]);
        $payment = Payment::factory()->forOrder($order)->create();

        try {
            $this->verify->handle($payment, $this->staff);
            $this->fail('Expected the settlement to be refused.');
        } catch (PaymentFailedException $refused) {
            expect($refused->getMessage())->toContain('dikembalikan lewat dokumen refund')
                ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
                ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
        }
    });

    it('refuses an order that is already paid', function () {
        [$order, $payment] = orderAwaitingTransfer();
        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->verify->handle($payment->fresh(), $this->staff);
    })->throws(PaymentFailedException::class, 'sudah lunas');
});

describe('cash on delivery catches up later (ADR-040)', function () {
    it('moves only the money, because the goods went out on confirmation', function () {
        [$order, $payment] = orderAwaitingTransfer(
            method: PaymentMethod::factory()->cod()->create(),
        );

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Pending);

        $this->verify->handle($payment->fresh(), $this->staff);
        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('notes the collected cash on the timeline without changing the status', function () {
        [$order, $payment] = orderAwaitingTransfer(
            method: PaymentMethod::factory()->cod()->create(),
        );

        $this->verify->handle($payment->fresh(), $this->staff, 'Uang diterima kurir.');

        $last = $order->fresh()->statusHistories->last();

        expect($last->note)->toBe('Uang diterima kurir.')
            ->and($last->from_status)->toBe($last->to_status);
    });

    it('records COD cash even after the goods have been delivered', function () {
        [$order, $payment] = orderAwaitingTransfer(
            method: PaymentMethod::factory()->cod()->create(),
        );

        $transition = app(TransitionOrderStatus::class);
        $order = $transition->handle($order->fresh(), OrderStatus::Processing);
        $order = $transition->handle($order, OrderStatus::Shipped);
        $transition->handle($order, OrderStatus::Delivered);

        expect($this->verify->handle($payment->fresh(), $this->staff))->toBeTrue()
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    });
});

describe('turning a payment down', function () {
    it('leaves the order payable and its stock held', function () {
        [$order, $payment] = orderAwaitingTransfer();

        expect(app(FailPayment::class)->handle($payment, 'Nominal kurang Rp5.000.', $this->staff))
            ->toBeTrue();

        $order->refresh();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($payment->fresh()->failure_reason)->toBe('Nominal kurang Rp5.000.')
            ->and($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Failed)
            ->and($order->isPayable())->toBeTrue()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Active);
    });

    it('answers false for an attempt that has already finished', function () {
        [, $payment] = orderAwaitingTransfer();
        $fail = app(FailPayment::class);

        expect($fail->handle($payment, 'Pertama.', $this->staff))->toBeTrue()
            ->and($fail->handle($payment->fresh(), 'Kedua.', $this->staff))->toBeFalse()
            ->and($payment->fresh()->failure_reason)->toBe('Pertama.');
    });
});

describe('expiring a payment (P2-E1-T3, ADR-041)', function () {
    it('closes an attempt whose deadline has passed', function () {
        [$order, $payment] = orderAwaitingTransfer();

        expect(app(ExpirePayment::class)->handle($payment))->toBeTrue()
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Expired);
    });

    it('sweeps only the attempts that are actually overdue', function () {
        [, $overdue] = orderAwaitingTransfer();
        $overdue->update(['expires_at' => now()->subMinute()]);

        [, $live] = orderAwaitingTransfer();

        expect(app(ExpirePayment::class)->sweep())->toBe(1)
            ->and($overdue->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($live->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    });

    it('is closed by the order being cancelled, with a reason that is true', function () {
        [$order, $payment] = orderAwaitingTransfer();

        $transition = app(TransitionOrderStatus::class);
        $transition->handle($order, OrderStatus::Cancelled, $this->staff, 'Salah pilih varian.');

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($payment->fresh()->failure_reason)->toContain('Pesanan dibatalkan')
            ->and($transition->closedPayments)->toBe(1);
    });

    it('is closed by the order expiring, with the deadline as the reason', function () {
        [$order, $payment] = orderAwaitingTransfer();

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Expired);

        expect($payment->fresh()->failure_reason)->toBe(ExpirePayment::DEADLINE_PASSED);
    });

    it('leaves the order payment_status to the transition that closed it', function () {
        [$order, $payment] = orderAwaitingTransfer();

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->staff);

        // Cancelled, not expired: the attempt lapsed, the order did not.
        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired);
    });
});
