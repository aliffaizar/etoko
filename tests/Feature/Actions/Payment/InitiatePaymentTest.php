<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Payment\InitiatePayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\MidtransApiException;
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
use App\Models\Warehouse;

/*
 * Asking the customer for the money (P2-E1-T2, T3, T7).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->initiate = app(InitiatePayment::class);
});

/**
 * An unpaid order holding stock, as checkout leaves one.
 */
function payableOrder(int $grandTotal = 500_000, int $held = 2, int $onHand = 10): Order
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch(test()->branch)->create([
        'subtotal' => $grandTotal,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => $grandTotal,
        'shipping_city_name' => 'Jakarta Selatan',
        'shipping_province_name' => 'DKI Jakarta',
    ]);

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    return $order->fresh();
}

describe('opening an attempt', function () {
    it('writes the attempt with the method snapshotted onto it', function () {
        $order = payableOrder();
        $method = PaymentMethod::factory()->create(['name' => 'Transfer Bank BCA']);

        $payment = $this->initiate->handle($order, $method);

        expect($payment->order_id)->toBe($order->getKey())
            ->and($payment->branch_id)->toBe($this->branch->getKey())
            ->and($payment->method_name)->toBe('Transfer Bank BCA')
            ->and($payment->method_type)->toBe($method->type)
            ->and($payment->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($payment->amount)->toBe(500_000)
            ->and($payment->fee_amount)->toBe(0)
            ->and($payment->reference)->toStartWith($order->order_number);
    });

    it('keeps the snapshot after the method is renamed (ADR-007)', function () {
        $payment = $this->initiate->handle(
            payableOrder(),
            $method = PaymentMethod::factory()->create(['name' => 'Transfer Bank BCA']),
        );

        $method->update(['name' => 'Transfer BCA (lama)']);

        expect($payment->fresh()->method_name)->toBe('Transfer Bank BCA');
    });

    it('charges the method fee on top of the order, leaving the order total alone (ADR-039)', function () {
        $order = payableOrder(grandTotal: 400_000);
        $method = PaymentMethod::factory()->withFee(4_000, 1)->create();

        $payment = $this->initiate->handle($order, $method);

        expect($payment->fee_amount)->toBe(8_000)
            ->and($payment->amount)->toBe(408_000)
            ->and($order->fresh()->grand_total)->toBe(400_000);
    });

    it('takes the payment deadline from the order stock hold (ADR-041)', function () {
        $order = payableOrder();
        $holdExpiry = StockReservation::acrossBranches()->sole()->expires_at;

        $payment = $this->initiate->handle($order, PaymentMethod::factory()->create());

        expect($payment->expires_at?->toIso8601String())->toBe($holdExpiry?->toIso8601String());
    });

    it('leaves a manual transfer order unpaid until a receipt arrives', function () {
        $order = payableOrder();

        $this->initiate->handle($order, PaymentMethod::factory()->create());

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    });

    it('marks a gateway order pending, because the gateway is holding it', function () {
        config()->set('services.midtrans.server_key', 'SB-Mid-server-test');
        fakeMidtrans()->chargeAnswers([
            'status_code' => '201',
            'transaction_id' => 'mt-txn-1',
            'transaction_status' => 'pending',
            'va_numbers' => [['bank' => 'bca', 'va_number' => '81234567890']],
        ]);

        $order = payableOrder();

        $payment = $this->initiate->handle($order, PaymentMethod::factory()->midtrans()->create());

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending)
            ->and($payment->va_number)->toBe('81234567890')
            ->and($payment->external_id)->toBe('mt-txn-1');
    });

    it('refuses a gateway method that has no server key, rather than calling the gateway without one', function () {
        config()->set('services.midtrans.server_key', null);

        $this->initiate->handle(payableOrder(), PaymentMethod::factory()->midtrans()->create());
    })->throws(PaymentFailedException::class, 'MIDTRANS_SERVER_KEY');

    it('leaves a failed charge recorded as a failed attempt, not deleted', function () {
        config()->set('services.midtrans.server_key', 'SB-Mid-server-test');
        fakeMidtrans()->chargeAnswers(MidtransApiException::refused(400, [
            'status_code' => '400',
            'validation_messages' => ['transaction_details.gross_amount is not valid'],
        ]));

        $order = payableOrder();

        try {
            $this->initiate->handle($order, PaymentMethod::factory()->midtrans()->create());
        } catch (PaymentFailedException) {
            // asserted below
        }

        $payment = Payment::acrossBranches()->sole();

        expect($payment->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($payment->failure_reason)->toContain('gross_amount')
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Failed);
    });

    it('gives each attempt its own reference, so a retry is a new transaction', function () {
        $order = payableOrder();
        $method = PaymentMethod::factory()->create();

        $first = $this->initiate->handle($order, $method);
        $second = $this->initiate->handle($order->fresh(), $method);

        expect($second->reference)->not->toBe($first->reference);
    });

    it('closes the previous attempt so one order never has two live asks', function () {
        $order = payableOrder();
        $method = PaymentMethod::factory()->create();

        $first = $this->initiate->handle($order, $method);
        $second = $this->initiate->handle($order->fresh(), PaymentMethod::factory()->create());

        expect($first->fresh()->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($first->fresh()->failure_reason)->toContain('Digantikan')
            ->and($second->status)->toBe(PaymentTransactionStatus::Pending)
            ->and(Payment::acrossBranches()->open()->count())->toBe(1);
    });
});

describe('refusing an attempt', function () {
    it('refuses a method that is switched off', function () {
        $this->initiate->handle(payableOrder(), PaymentMethod::factory()->inactive()->create());
    })->throws(PaymentFailedException::class, 'sedang tidak tersedia');

    it('refuses a method the order is not eligible for, naming the reason (P2-E1-T7)', function () {
        $order = payableOrder(grandTotal: 3_000_000);
        $method = PaymentMethod::factory()->cod()->betweenAmounts(null, 2_000_000)->create();

        $this->initiate->handle($order, $method);
    })->throws(PaymentFailedException::class, 'di atas batas maksimum');

    it('refuses an order that is already paid', function () {
        $order = payableOrder();
        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->initiate->handle($order->fresh(), PaymentMethod::factory()->create());
    })->throws(PaymentFailedException::class, 'sudah lunas');

    it('refuses an order that has moved past waiting for payment', function () {
        $order = payableOrder();
        $order->update(['status' => OrderStatus::Cancelled]);

        $this->initiate->handle($order->fresh(), PaymentMethod::factory()->create());
    })->throws(PaymentFailedException::class, 'tidak dapat dibayar lagi');

    it('writes nothing when it refuses', function () {
        $order = payableOrder();

        try {
            $this->initiate->handle($order, PaymentMethod::factory()->inactive()->create());
        } catch (PaymentFailedException) {
            // asserted below
        }

        expect(Payment::acrossBranches()->count())->toBe(0)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    });
});

describe('cash on delivery confirms the order outright (ADR-040)', function () {
    it('moves the order to paid and commits its stock', function () {
        $order = payableOrder(held: 2, onHand: 10);
        $method = PaymentMethod::factory()->cod()->create();

        $payment = $this->initiate->handle($order, $method);
        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Committed)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending);
    });

    it('leaves the money outstanding, because nobody has handed any over', function () {
        $order = payableOrder();

        $this->initiate->handle($order, PaymentMethod::factory()->cod()->create());

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);
    });

    it('takes the goods off the shelf, with a ledger row to explain it', function () {
        $order = payableOrder(held: 2, onHand: 10);

        $this->initiate->handle($order, PaymentMethod::factory()->cod()->create());

        $stock = Stock::acrossBranches()->sole();

        expect($stock->quantity)->toBe(8)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('sets no payment deadline, because no hold is ticking down', function () {
        $order = payableOrder();

        $payment = $this->initiate->handle($order, PaymentMethod::factory()->cod()->create());

        expect($payment->expires_at)->toBeNull();
    });

    it('notes the confirmation on the order timeline', function () {
        $order = payableOrder();

        $this->initiate->handle($order, PaymentMethod::factory()->cod()->create());

        expect($order->fresh()->statusHistories->last()->note)->toContain('COD dikonfirmasi');
    });
});
