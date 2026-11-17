<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\Midtrans\MidtransClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeMidtransClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // The Midtrans SDK talks cURL directly, so `Http::fake()` does not stop
    // it. Every test therefore starts with the fake client bound, and one
    // that sets a server key and forgets to stub fails instead of reaching
    // the sandbox.
    ->beforeEach(fn () => fakeMidtrans())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Put a fake in place of the Midtrans SDK client for this test.
 *
 * Nothing in the suite reaches Midtrans: a call the test did not stub throws.
 */
function fakeMidtrans(): FakeMidtransClient
{
    $fake = new FakeMidtransClient;

    app()->instance(MidtransClient::class, $fake);

    return $fake;
}

/**
 * What Midtrans' status API answers for a payment (`GET /v2/{order_id}/status`).
 *
 * @return array<string, mixed>
 */
function midtransStatusAnswer(Payment $payment, string $transactionStatus = 'settlement'): array
{
    return [
        'order_id' => $payment->reference,
        'status_code' => $transactionStatus === 'pending' ? '201' : '200',
        'gross_amount' => number_format($payment->amount, 2, '.', ''),
        'transaction_status' => $transactionStatus,
        'transaction_id' => 'mt-status-1',
        'payment_type' => 'bank_transfer',
    ];
}

/**
 * A paid order with known lines, for the report tests (P2-E7-T10).
 *
 * Each line is `[unit price, quantity, discount, refunded quantity]` with an
 * optional `product` key; the order's totals are worked out from them so the
 * expected figures in a test can be added up by hand.
 *
 * @param  array<int, array{0: int, 1: int, 2?: int, 3?: int, product?: Product|null, sku?: string}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function reportSale(Branch $branch, string $paidAt, array $lines, array $attributes = []): Order
{
    $subtotal = 0;
    $discount = 0;

    foreach ($lines as $line) {
        $subtotal += $line[0] * $line[1];
        $discount += $line[2] ?? 0;
    }

    $shipping = $attributes['shipping_cost'] ?? 0;

    $order = Order::factory()->forBranch($branch)->paid()->create([
        'placed_at' => $paidAt,
        'paid_at' => $paidAt,
        'subtotal' => $subtotal,
        'discount_total' => $discount,
        'shipping_cost' => $shipping,
        'grand_total' => $subtotal - $discount + $shipping,
        ...$attributes,
    ]);

    foreach ($lines as $index => $line) {
        OrderItem::factory()->forOrder($order)->create([
            'product_id' => ($line['product'] ?? null)?->getKey(),
            'sku' => $line['sku'] ?? "SKU-{$order->id}-{$index}",
            'unit_price' => $line[0],
            'quantity' => $line[1],
            'discount_amount' => $line[2] ?? 0,
            'subtotal' => $line[0] * $line[1] - ($line[2] ?? 0),
            'refunded_quantity' => $line[3] ?? 0,
        ]);
    }

    return $order;
}
