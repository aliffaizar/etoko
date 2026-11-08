<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
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
 * Where payment providers tell us what happened (P2-E1-T6).
 *
 * The only unauthenticated, CSRF-free endpoint in the application, so these
 * tests are mostly about what it refuses. The status codes matter as much as
 * the effects: a gateway resends anything it is not answered with a 2xx, so
 * "already handled" has to be a 200 and "this will never work" has to be a
 * 400.
 */

const WEBHOOK_KEY = 'SB-Mid-server-WEBHOOK';

beforeEach(function () {
    config()->set('services.midtrans.server_key', WEBHOOK_KEY);

    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->method = PaymentMethod::factory()->midtrans(PaymentMethodType::VirtualAccount)->create();
});

/**
 * An unpaid order holding stock, with a pending Midtrans attempt against it.
 *
 * @return array{0: Order, 1: Payment}
 */
function webhookOrder(int $held = 2, int $onHand = 10, ?PaymentMethod $method = null): array
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signedPayload(Payment $payment, string $status = 'settlement', array $overrides = []): array
{
    $gross = number_format($payment->amount, 2, '.', '');
    $orderId = $overrides['order_id'] ?? $payment->reference;
    $grossAmount = $overrides['gross_amount'] ?? $gross;

    return [
        'order_id' => $orderId,
        'status_code' => '200',
        'gross_amount' => $grossAmount,
        'transaction_status' => $status,
        'transaction_id' => 'mt-txn-77',
        'signature_key' => hash('sha512', $orderId.'200'.$grossAmount.WEBHOOK_KEY),
        ...$overrides,
    ];
}

function postWebhook(array $payload, string $driver = 'midtrans')
{
    return test()->postJson(route('webhook.payment', ['driver' => $driver]), $payload);
}

describe('a genuine settlement', function () {
    it('marks the payment and the order paid, and commits the stock', function () {
        [$order, $payment] = webhookOrder(held: 2, onHand: 10);

        postWebhook(signedPayload($payment))->assertOk();

        $order->refresh();
        $stock = Stock::acrossBranches()->sole();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($payment->fresh()->external_id)->toBe('mt-txn-77')
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Committed)
            ->and($stock->quantity)->toBe(8)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1);
    });

    it('keeps the payload so a dispute can be read in the gateway own words', function () {
        [, $payment] = webhookOrder();

        postWebhook(signedPayload($payment))->assertOk();

        expect($payment->fresh()->raw_payload)
            ->toHaveKey('transaction_status')
            ->and($payment->fresh()->raw_payload['transaction_status'])->toBe('settlement');
    });

    it('needs no session or CSRF token, because the caller has neither', function () {
        [, $payment] = webhookOrder();

        // `post` rather than `postJson`, so the request goes through as a
        // plain form submission with no token — which would be a 419 inside
        // the `web` group.
        $this->post(route('webhook.payment', ['driver' => 'midtrans']), signedPayload($payment))
            ->assertOk();
    });

    it('does not commit the stock twice when the gateway resends it', function () {
        [, $payment] = webhookOrder(held: 2, onHand: 10);

        postWebhook(signedPayload($payment))->assertOk();
        postWebhook(signedPayload($payment))->assertOk();

        expect(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(1)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(8);
    });
});

describe('what it refuses', function () {
    it('rejects an unsigned payload with a 400, so the gateway stops retrying', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, overrides: ['signature_key' => 'forged']))
            ->assertStatus(400);

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('rejects a settlement for the wrong amount', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, overrides: ['gross_amount' => '1000.00']))
            ->assertStatus(400);

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);
    });

    it('still keeps the payload of a mismatched amount, because that is the evidence', function () {
        [, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, overrides: ['gross_amount' => '1000.00']));

        expect($payment->fresh()->raw_payload['gross_amount'])->toBe('1000.00');
    });

    it('rejects a reference it has never issued', function () {
        webhookOrder();

        $stranger = Payment::factory()->make(['reference' => 'ORD-19990101-0001-ZZZZZZZZ']);

        postWebhook(signedPayload($stranger))->assertStatus(400);
    });

    it('refuses to settle a manual payment with a midtrans signature', function () {
        [$order, $payment] = webhookOrder(method: PaymentMethod::factory()->create());

        postWebhook(signedPayload($payment))->assertStatus(400);

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('refuses a manual callback outright', function () {
        [, $payment] = webhookOrder(method: PaymentMethod::factory()->create());

        postWebhook(signedPayload($payment), driver: 'manual')->assertStatus(400);
    });

    it('answers 404 for a provider it does not know', function () {
        $this->postJson('/webhook/payment/stripe', [])->assertNotFound();
    });
});

describe('the other outcomes', function () {
    it('closes a denied transaction and leaves the order payable', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, 'deny'))->assertOk();

        $order->refresh();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Failed)
            ->and($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Failed)
            ->and($order->isPayable())->toBeTrue()
            ->and(StockReservation::acrossBranches()->sole()->status)
            ->toBe(ReservationStatus::Active);
    });

    it('expires a lapsed virtual account', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, 'expire'))->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Expired)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Expired);
    });

    it('records a refund the gateway reports, without touching the order lines', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, 'refund'))->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Refunded)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
    });

    it('accepts and ignores a pending notification', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment, 'pending'))->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('accepts a duplicate settlement without changing anything', function () {
        [, $payment] = webhookOrder();

        postWebhook(signedPayload($payment))->assertOk();
        $settledAt = $payment->fresh()->paid_at;

        postWebhook(signedPayload($payment))->assertOk();

        expect($payment->fresh()->paid_at?->toIso8601String())
            ->toBe($settledAt?->toIso8601String());
    });

    it('does not undo a settled payment when a later deny arrives', function () {
        [$order, $payment] = webhookOrder();

        postWebhook(signedPayload($payment))->assertOk();
        postWebhook(signedPayload($payment, 'deny'))->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('rejects a settlement for an order whose stock has gone back on sale', function () {
        [$order, $payment] = webhookOrder();

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Expired);

        // The transition closed the attempt with the order, so the callback
        // now finds a finished attempt: accepted so the gateway stops, and
        // nothing is marked paid.
        postWebhook(signedPayload($payment->fresh()))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Expired)
            ->and(StockMovement::acrossBranches()->where('type', 'sale_out')->count())->toBe(0);
    });
});
