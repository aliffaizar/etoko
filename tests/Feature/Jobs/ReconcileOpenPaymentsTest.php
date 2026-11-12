<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\MidtransApiException;
use App\Jobs\ReconcileOpenPayments;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;

/*
 * Asking Midtrans about every open attempt, on a schedule (P2-E1-T6, ADR-053).
 *
 * The application has no webhook, so this job is how a payment made by
 * somebody who never reopens their order page is noticed at all.
 */

beforeEach(function () {
    config()->set('services.midtrans.server_key', 'SB-Mid-server-FAKEKEY');

    $this->branch = Branch::factory()->default()->create();
    $this->midtrans = fakeMidtrans();
});

/**
 * An order waiting to be paid, with one open attempt against `$method`.
 *
 * @return array{0: Order, 1: Payment}
 */
function orderWithOpenAttempt(PaymentMethod $method): array
{
    $order = Order::factory()->forBranch(test()->branch)->create([
        'payment_status' => PaymentStatus::Pending,
    ]);
    $payment = Payment::factory()->forOrder($order)->forMethod($method)->create([
        'expires_at' => now()->addDay(),
    ]);

    return [$order, $payment];
}

function reconcileOpenPayments(): void
{
    app()->call([new ReconcileOpenPayments, 'handle']);
}

it('settles an order whose customer paid and never came back to the page', function () {
    [$order, $payment] = orderWithOpenAttempt(PaymentMethod::factory()->midtrans()->create());
    $this->midtrans->statusAnswers(midtransStatusAnswer($payment));

    reconcileOpenPayments();

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Success)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('leaves an attempt open while Midtrans still says pending', function () {
    [$order, $payment] = orderWithOpenAttempt(PaymentMethod::factory()->midtrans()->create());
    $this->midtrans->statusAnswers(midtransStatusAnswer($payment, 'pending'));

    reconcileOpenPayments();

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('asks about gateway attempts only, never about manual ones', function () {
    [, $gateway] = orderWithOpenAttempt(PaymentMethod::factory()->midtrans()->create());
    orderWithOpenAttempt(PaymentMethod::factory()->cod()->create());
    $this->midtrans->statusAnswers(midtransStatusAnswer($gateway, 'pending'));

    reconcileOpenPayments();

    expect($this->midtrans->statusChecks)->toBe([$gateway->reference]);
});

it('does not ask about an attempt that has already closed', function () {
    $order = Order::factory()->forBranch($this->branch)->create();
    Payment::factory()->forOrder($order)->forMethod(PaymentMethod::factory()->midtrans()->create())
        ->success()->create();

    reconcileOpenPayments();

    expect($this->midtrans->statusChecks)->toBe([]);
});

it('carries on to the next attempt when Midtrans cannot answer about one', function () {
    [$firstOrder, $first] = orderWithOpenAttempt(PaymentMethod::factory()->midtrans()->create());
    [$secondOrder, $second] = orderWithOpenAttempt(PaymentMethod::factory()->midtrans()->create());
    $this->midtrans->statusAnswers(fn (string $reference): array => $reference === $first->reference
        ? throw MidtransApiException::unreachable()
        : midtransStatusAnswer($second));

    reconcileOpenPayments();

    expect($firstOrder->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($first->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
        ->and($secondOrder->fresh()->status)->toBe(OrderStatus::Paid);
});
