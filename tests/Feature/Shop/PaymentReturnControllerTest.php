<?php

use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;

/*
 * Where Snap sends the customer back to (P2-E1-T5).
 *
 * The query string Midtrans appends is **unsigned**, so the endpoint reads one
 * thing from it — which order to show — and writes nothing. The payment's real
 * status is the webhook's to set, and the tests below are mostly about that
 * distinction holding.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->stranger = User::factory()->customer()->create();
    $this->method = PaymentMethod::factory()->create(['name' => 'Virtual Account (Midtrans)']);
});

/**
 * An open gateway payment on one of the customer's orders.
 */
function returningPayment(?User $owner = null): Payment
{
    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer($owner ?? test()->customer)
        ->create();

    return Payment::factory()
        ->forOrder($order)
        ->forMethod(test()->method)
        ->create(['payment_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/abc']);
}

it('sends the customer to the order the reference belongs to', function () {
    $payment = returningPayment();

    $this->actingAs($this->customer)
        ->get(route('shop.payments.return', ['order_id' => $payment->reference]))
        ->assertRedirect(route('shop.account.orders.show', $payment->order));
});

it('does not settle the payment when the query string claims it succeeded', function () {
    $payment = returningPayment();

    $this->actingAs($this->customer)
        ->get(route('shop.payments.return', [
            'order_id' => $payment->reference,
            'status_code' => '200',
            'transaction_status' => 'settlement',
        ]))
        ->assertRedirect(route('shop.account.orders.show', $payment->order));

    expect($payment->fresh()->status)->toBe(PaymentTransactionStatus::Pending)
        ->and($payment->fresh()->paid_at)->toBeNull()
        ->and($payment->order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
});

it('sends the customer to their order list without saying whether the reference exists', function (string $label, callable $query) {
    $mine = returningPayment();
    $theirs = returningPayment(test()->stranger);

    $this->actingAs($this->customer)
        ->get(route('shop.payments.return', $query($mine, $theirs)))
        ->assertRedirect(route('shop.account.orders.index'));
})->with([
    ['no order_id at all', fn () => []],
    ['an empty order_id', fn () => ['order_id' => '']],
    ['an unknown reference', fn () => ['order_id' => 'ORD-20260101-0001-NOTAREAL']],
    ['an array instead of a reference', fn () => ['order_id' => ['a', 'b']]],
    ['another customer\'s reference', fn (Payment $mine, Payment $theirs) => ['order_id' => $theirs->reference]],
]);

it('redirects a signed-out visitor to the login screen', function () {
    $payment = returningPayment();

    $this->get(route('shop.payments.return', ['order_id' => $payment->reference]))
        ->assertRedirect(route('login'));
});
