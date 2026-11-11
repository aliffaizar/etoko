<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\InitiatePayment;
use App\Actions\Payment\VerifyPayment;
use App\Actions\Returns\ApproveReturn;
use App\Actions\Returns\CompleteReturn;
use App\Actions\Returns\FailRefund;
use App\Actions\Returns\ProcessRefund;
use App\Actions\Returns\ReceiveReturn;
use App\Actions\Returns\SettleRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * Sending money back (P2-E4-T4, T5).
 *
 * Two properties the whole epic rests on, and both are tested here:
 *
 * 1. **An order's own totals are never rewritten by a refund** (ADR-047).
 *    `subtotal`, `grand_total` and every line's `subtotal` stay as they were
 *    sold, so last month's sales figures do not move when a customer is paid
 *    back. What changes is `payment_status`, the lines' `refunded_quantity`,
 *    and the sum of the `refunds` rows.
 * 2. **A partial refund does not push the order into a final status**
 *    (ADR-048). `partially_refunded` is final in the state machine, so moving
 *    a delivered order into it would make it uncompletable *and* unreturnable
 *    — a customer who sent one item back could never send a second.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->central = User::factory()->adminPusat()->create();
    $this->customer = User::factory()->customer()->create();
    $this->action = app(ProcessRefund::class);
});

/**
 * A delivered, paid-for order of `$quantity` units at `$unitPrice`, with a
 * received return against `$returned` of them.
 *
 * Driven through the real actions throughout — the hold, the settlement, the
 * four transitions and the receiving — so the figures a refund is measured
 * against are the ones production produces.
 *
 * @return array{0: Order, 1: OrderItem, 2: OrderReturn, 3: Payment}
 */
function settledOrderWithReceivedReturn(
    int $quantity = 2,
    int $unitPrice = 100_000,
    int $returned = 1,
    int $shipping = 20_000,
): array {
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(50)->create();

    $order = Order::factory()->forBranch(test()->branch)->forCustomer(test()->customer)->create([
        'subtotal' => $unitPrice * $quantity,
        'shipping_cost' => $shipping,
        'tax_total' => 0,
        'discount_total' => 0,
        'grand_total' => $unitPrice * $quantity + $shipping,
    ]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->forVariant($variant)
        ->quantity($quantity)
        ->unitPrice($unitPrice)
        ->create();

    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $quantity]]);

    $payment = app(InitiatePayment::class)->handle($order->fresh(), PaymentMethod::factory()->create());
    app(VerifyPayment::class)->handle($payment, test()->central);

    $transition = app(TransitionOrderStatus::class);
    $transition->handle($order->fresh(), OrderStatus::Processing);
    $transition->handle($order->fresh(), OrderStatus::Shipped);
    $transition->handle($order->fresh(), OrderStatus::Delivered);

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, $returned)->create();
    app(ApproveReturn::class)->handle($return, test()->central);
    app(ReceiveReturn::class)->handle($return->fresh(), [], test()->central);

    return [$order->fresh(), $item->fresh(), $return->fresh(), $payment->fresh()];
}

/**
 * The same order and return, but paid through a Midtrans-driver method, so a
 * gateway refund has a transaction the provider would know about.
 *
 * The charge is faked by the caller before this runs; what each test fakes
 * afterwards is the refund call.
 *
 * @return array{0: Order, 1: OrderItem, 2: OrderReturn, 3: Payment}
 */
function gatewaySettledOrder(): array
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(50)->create();

    $order = Order::factory()->forBranch(test()->branch)->forCustomer(test()->customer)->create([
        'subtotal' => 100_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'discount_total' => 0,
        'grand_total' => 100_000,
    ]);

    $item = OrderItem::factory()
        ->forOrder($order)
        ->forVariant($variant)
        ->quantity(1)
        ->unitPrice(100_000)
        ->create();

    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => 1]]);

    $payment = app(InitiatePayment::class)->handle(
        $order->fresh(),
        PaymentMethod::factory()->midtrans()->create(),
    );
    app(VerifyPayment::class)->handle($payment, test()->central);

    $transition = app(TransitionOrderStatus::class);
    $transition->handle($order->fresh(), OrderStatus::Processing);
    $transition->handle($order->fresh(), OrderStatus::Shipped);
    $transition->handle($order->fresh(), OrderStatus::Delivered);

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();
    app(ApproveReturn::class)->handle($return, test()->central);
    app(ReceiveReturn::class)->handle($return->fresh(), [], test()->central);

    return [$order->fresh(), $item->fresh(), $return->fresh(), $payment->fresh()];
}

/**
 * @return array{amount: int, method: RefundMethod, return_id: int|null, payment_id: int|null, account_info: string|null, reference: string|null, notes: string|null}
 */
function refundRequest(int $amount, array $overrides = []): array
{
    return [
        'amount' => $amount,
        'method' => RefundMethod::Transfer,
        'return_id' => null,
        'payment_id' => null,
        'account_info' => 'BCA 1234567890 a/n Budi',
        'reference' => null,
        'notes' => null,
        ...$overrides,
    ];
}

describe('opening a refund', function () {
    it('writes a numbered pending document that nobody has sent yet', function () {
        [$order, , $return] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id]),
            $this->central,
        );

        expect($refund->code)->toStartWith('RFD-')
            ->and($refund->status)->toBe(RefundStatus::Pending)
            ->and($refund->amount)->toBe(100_000)
            ->and($refund->branch_id)->toBe($this->branch->id)
            ->and($refund->return_id)->toBe($return->id)
            ->and($refund->created_by)->toBe($this->central->id);

        // Pending money has not gone anywhere, so the order is untouched.
        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->fresh()->refundedAmount())->toBe(0);
    });

    it('settles at once when the actor says they have already sent the money', function () {
        [$order, , $return] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF123']),
            $this->central,
        );

        expect($refund->status)->toBe(RefundStatus::Processed)
            ->and($refund->reference)->toBe('TRF123')
            ->and($refund->processed_by)->toBe($this->central->id);
    });

    it('allows a refund with no return behind it at all', function () {
        [$order] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle($order, refundRequest(20_000), $this->central);

        expect($refund->return_id)->toBeNull()
            ->and($refund->amount)->toBe(20_000);
    });

    it('refuses more than the order has left to give back', function () {
        [$order] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000, shipping: 20_000);

        expect(fn () => $this->action->handle($order, refundRequest(220_001), $this->central))
            ->toThrow(ValidationException::class, 'melebihi sisa dana pesanan');
    });

    it('refuses a return belonging to another order', function () {
        [$order] = settledOrderWithReceivedReturn();
        [, , $foreign] = settledOrderWithReceivedReturn();

        expect(fn () => $this->action->handle(
            $order,
            refundRequest(10_000, ['return_id' => $foreign->id]),
            $this->central,
        ))->toThrow(ValidationException::class, 'bukan milik pesanan ini');
    });

    it('refuses to pay against goods that have not come back yet', function () {
        [$order, $item] = settledOrderWithReceivedReturn();

        $pending = OrderReturn::factory()->forOrder($order)->create();
        ReturnItem::factory()->forReturn($pending)->forOrderItem($item, 1)->create();

        expect(fn () => $this->action->handle(
            $order,
            refundRequest(10_000, ['return_id' => $pending->id]),
            $this->central,
        ))->toThrow(ValidationException::class, 'belum diterima di cabang');
    });

    it('refuses an exchange, which ends in goods rather than money', function () {
        [$order, $item] = settledOrderWithReceivedReturn();

        $exchange = OrderReturn::factory()->forOrder($order)->exchange()->received($this->central)->create();
        ReturnItem::factory()->forReturn($exchange)->forOrderItem($item, 1)->create();

        expect(fn () => $this->action->handle(
            $order,
            refundRequest(10_000, ['return_id' => $exchange->id]),
            $this->central,
        ))->toThrow(ValidationException::class, 'tukar barang');
    });

    it('refuses store credit, which the application cannot yet serve', function () {
        [$order] = settledOrderWithReceivedReturn();

        expect(fn () => $this->action->handle(
            $order,
            refundRequest(10_000, ['method' => RefundMethod::StoreCredit]),
            $this->central,
        ))->toThrow(ValidationException::class, 'saldo toko belum tersedia');
    });

    it('refuses a gateway refund with no payment to reverse', function () {
        [$order] = settledOrderWithReceivedReturn();

        expect(fn () => $this->action->handle(
            $order,
            refundRequest(10_000, ['method' => RefundMethod::Gateway]),
            $this->central,
        ))->toThrow(ValidationException::class, 'Pilih pembayaran');
    });
});

describe('a partial refund', function () {
    it('leaves the order status alone and only moves payment_status', function () {
        [$order, , $return] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000);

        $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Delivered)
            ->and($order->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
            ->and($order->refundedAmount())->toBe(100_000)
            ->and($order->refundableAmount())->toBe(120_000);
    });

    it('does not rewrite the order or its lines (P2-E4-T5)', function () {
        [$order, $item, $return] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000);

        $before = [
            'subtotal' => $order->subtotal,
            'shipping_cost' => $order->shipping_cost,
            'grand_total' => $order->grand_total,
            'line_subtotal' => $item->subtotal,
            'line_quantity' => $item->quantity,
        ];

        $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        $order->refresh();
        $item->refresh();

        expect($order->subtotal)->toBe($before['subtotal'])
            ->and($order->shipping_cost)->toBe($before['shipping_cost'])
            ->and($order->grand_total)->toBe($before['grand_total'])
            ->and($item->subtotal)->toBe($before['line_subtotal'])
            ->and($item->quantity)->toBe($before['line_quantity'])
            // The one line-level figure a return does change: units taken back.
            ->and($item->refunded_quantity)->toBe(1);
    });

    it('leaves the order returnable, so a second item can still be sent back', function () {
        [$order, $item, $return] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000);

        $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        expect($order->fresh()->isReturnable())->toBeTrue()
            ->and($item->fresh()->returnableQuantity())->toBe(1);
    });

    it('leaves the source payment standing, because only part of it went back', function () {
        [$order, , $return, $payment] = settledOrderWithReceivedReturn();

        $this->action->handle(
            $order,
            refundRequest(100_000, [
                'return_id' => $return->id,
                'payment_id' => $payment->id,
                'reference' => 'TRF1',
            ]),
            $this->central,
        );

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success);
    });

    it('keeps the return open when the money does not cover the goods', function () {
        [$order, , $return] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000, returned: 1);

        // 90.000 against goods worth 100.000 — a restocking fee withheld.
        $this->action->handle(
            $order,
            refundRequest(90_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        expect($return->fresh()->status)->toBe(ReturnStatus::Received);
    });
});

describe('a full refund', function () {
    it('moves the order to refunded and marks the payment refunded', function () {
        [$order, , $return, $payment] = settledOrderWithReceivedReturn(
            quantity: 1,
            unitPrice: 100_000,
            returned: 1,
            shipping: 20_000,
        );

        $this->action->handle(
            $order,
            refundRequest(120_000, [
                'return_id' => $return->id,
                'payment_id' => $payment->id,
                'reference' => 'TRF-FULL',
            ]),
            $this->central,
        );

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Refunded)
            ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($order->refundableAmount())->toBe(0)
            ->and($payment->fresh()->status)->toBe(PaymentTransactionStatus::Refunded);
    });

    it('closes the return once the money covers its goods', function () {
        [$order, , $return] = settledOrderWithReceivedReturn(quantity: 1, unitPrice: 100_000, returned: 1);

        $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        $return->refresh();

        expect($return->status)->toBe(ReturnStatus::Completed)
            ->and($return->completed_at)->not->toBeNull();
    });

    it('adds two partial refunds up to a full one', function () {
        [$order, , $return] = settledOrderWithReceivedReturn(
            quantity: 1,
            unitPrice: 100_000,
            returned: 1,
            shipping: 0,
        );

        $this->action->handle(
            $order,
            refundRequest(40_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);

        $this->action->handle(
            $order->fresh(),
            refundRequest(60_000, ['return_id' => $return->id, 'reference' => 'TRF2']),
            $this->central,
        );

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($order->refundedAmount())->toBe(100_000)
            ->and($order->status)->toBe(OrderStatus::Refunded);
    });
});

describe('settling and failing by hand', function () {
    it('answers false when a refund has already been settled', function () {
        [$order] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle($order, refundRequest(10_000), $this->central);
        $settle = app(SettleRefund::class);

        expect($settle->handle($refund, $this->central, 'TRF1'))->toBeTrue()
            ->and($settle->handle($refund->fresh(), $this->central, 'TRF1'))->toBeFalse();
    });

    it('gives a failed refund its amount back to what may still be returned', function () {
        [$order] = settledOrderWithReceivedReturn(quantity: 1, unitPrice: 100_000, shipping: 0);

        $refund = $this->action->handle($order, refundRequest(100_000), $this->central);

        app(FailRefund::class)->handle($refund, 'Rekening salah.', $this->central);
        $order->refresh();

        expect($refund->fresh()->status)->toBe(RefundStatus::Failed)
            ->and($order->refundedAmount())->toBe(0)
            ->and($order->refundableAmount())->toBe(100_000)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid);

        // And a corrected second refund still goes through for the full amount.
        $second = $this->action->handle(
            $order,
            refundRequest(100_000, ['reference' => 'TRF-OK']),
            $this->central,
        );

        expect($second->status)->toBe(RefundStatus::Processed);
    });

    it('refuses to fail a refund whose money has already gone', function () {
        [$order] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle(
            $order,
            refundRequest(10_000, ['reference' => 'TRF1']),
            $this->central,
        );

        expect(fn () => app(FailRefund::class)->handle($refund, 'Terlalu besar.', $this->central))
            ->toThrow(ValidationException::class, 'tidak dapat diubah lagi');
    });

    it('records the money on the order timeline', function () {
        [$order, , $return] = settledOrderWithReceivedReturn();

        $refund = $this->action->handle(
            $order,
            refundRequest(100_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        $notes = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->get()
            ->filter(fn (OrderStatusHistory $row): bool => str_contains((string) $row->note, $refund->code));

        expect($notes)->not->toBeEmpty();
    });
});

describe('closing a return by hand', function () {
    it('closes an exchange, which no refund will ever close', function () {
        [$order, $item] = settledOrderWithReceivedReturn();

        $exchange = OrderReturn::factory()->forOrder($order)->exchange()->received($this->central)->create();
        ReturnItem::factory()->forReturn($exchange)->forOrderItem($item, 1)->create();

        $closed = app(CompleteReturn::class)->handle($exchange->fresh(), $this->central, 'Pengganti sudah dikirim.');

        expect($closed->status)->toBe(ReturnStatus::Completed)
            ->and($closed->type)->toBe(ReturnType::Exchange);
    });

    it('refuses to close a refund-type return that nobody has paid', function () {
        [, , $return] = settledOrderWithReceivedReturn();

        expect(fn () => app(CompleteReturn::class)->handle($return, $this->central))
            ->toThrow(ValidationException::class, 'Belum ada dana yang dikembalikan');
    });

    it('closes a refund-type return the shop deliberately paid less for', function () {
        [$order, , $return] = settledOrderWithReceivedReturn(quantity: 2, unitPrice: 100_000, returned: 1);

        $this->action->handle(
            $order,
            refundRequest(90_000, ['return_id' => $return->id, 'reference' => 'TRF1']),
            $this->central,
        );

        $closed = app(CompleteReturn::class)->handle($return->fresh(), $this->central, 'Dipotong biaya kirim balik.');

        expect($closed->status)->toBe(ReturnStatus::Completed);
    });
});

describe('the refund table itself', function () {
    it('counts only settled rows towards what has gone back', function () {
        [$order] = settledOrderWithReceivedReturn(quantity: 1, unitPrice: 100_000, shipping: 0);

        Refund::factory()->forOrder($order)->amount(30_000)->create();
        Refund::factory()->forOrder($order)->amount(40_000)->processed()->create();
        Refund::factory()->forOrder($order)->amount(20_000)->failed()->create();

        expect($order->fresh()->refundedAmount())->toBe(40_000)
            ->and($order->fresh()->refundableAmount())->toBe(60_000);
    });
});

describe('a refund through the payment gateway', function () {
    beforeEach(function () {
        config()->set('services.midtrans.server_key', 'SB-Mid-server-FAKEKEY');
        config()->set('services.midtrans.snap_base_url', 'https://app.sandbox.midtrans.com');
        config()->set('services.midtrans.api_base_url', 'https://api.sandbox.midtrans.com');
    });

    it('settles the refund when the provider says the money has gone', function () {
        // Both calls stubbed in one go, by host: the charge goes to Snap and
        // the reversal to the core API, and `Http::fake()` merges rather than
        // replaces — a second blanket `*` stub would never be reached.
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 't',
                'redirect_url' => 'https://pay.test/t',
            ]),
            'api.sandbox.midtrans.com/*' => Http::response([
                'status_code' => '200',
                'refund_key' => 'RFD-OK',
            ]),
        ]);

        [$order, , $return, $payment] = gatewaySettledOrder();

        $refund = $this->action->handle($order, refundRequest(100_000, [
            'method' => RefundMethod::Gateway,
            'return_id' => $return->id,
            'payment_id' => $payment->id,
            'account_info' => null,
        ]), $this->central);

        expect($refund->failure_reason)->toBeNull()
            ->and($refund->status)->toBe(RefundStatus::Processed)
            ->and($refund->reference)->toBe('RFD-OK')
            ->and($order->fresh()->refundedAmount())->toBe(100_000);
    });

    it('leaves the refund pending when the provider has only accepted it', function () {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 't',
                'redirect_url' => 'https://pay.test/t',
            ]),
            'api.sandbox.midtrans.com/*' => Http::response([
                'status_code' => '202',
                'refund_key' => 'RFD-LATER',
            ]),
        ]);

        [$order, , $return, $payment] = gatewaySettledOrder();

        $refund = $this->action->handle($order, refundRequest(100_000, [
            'method' => RefundMethod::Gateway,
            'return_id' => $return->id,
            'payment_id' => $payment->id,
            'account_info' => null,
        ]), $this->central);

        expect($refund->status)->toBe(RefundStatus::Pending)
            ->and($refund->reference)->toBe('RFD-LATER')
            // Money the provider has not confirmed is money the customer does
            // not have, so the order has not moved.
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->fresh()->refundedAmount())->toBe(0);
    });

    it('records the provider refusing, without touching the order', function () {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 't',
                'redirect_url' => 'https://pay.test/t',
            ]),
            'api.sandbox.midtrans.com/*' => Http::response([
                'status_code' => '412',
                'status_message' => 'Transaksi tidak dapat direfund.',
            ]),
        ]);

        [$order, , $return, $payment] = gatewaySettledOrder();

        $refund = $this->action->handle($order, refundRequest(100_000, [
            'method' => RefundMethod::Gateway,
            'return_id' => $return->id,
            'payment_id' => $payment->id,
            'account_info' => null,
        ]), $this->central);

        expect($refund->status)->toBe(RefundStatus::Failed)
            ->and($refund->failure_reason)->not->toBeNull()
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->fresh()->refundableAmount())->toBe($order->grand_total);
    });

    it('records a failure rather than throwing when the driver has no provider to ask', function () {
        [$order, , $return, $payment] = settledOrderWithReceivedReturn(quantity: 1, unitPrice: 100_000, shipping: 0);

        // A manual-driver payment asked for a gateway refund: `ManualGateway`
        // answers "a person has to do this", which is not a settlement.
        $refund = $this->action->handle($order, refundRequest(100_000, [
            'method' => RefundMethod::Gateway,
            'return_id' => $return->id,
            'payment_id' => $payment->id,
            'account_info' => null,
        ]), $this->central);

        expect($refund->status)->toBe(RefundStatus::Pending)
            ->and($refund->notes)->toContain('manual')
            ->and($order->fresh()->refundedAmount())->toBe(0);
    });
});
