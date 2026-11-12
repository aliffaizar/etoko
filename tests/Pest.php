<?php

use App\Models\Payment;
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
