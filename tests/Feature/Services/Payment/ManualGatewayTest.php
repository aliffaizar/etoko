<?php

use App\Enums\PaymentDriver;
use App\Exceptions\PaymentFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\Drivers\ManualGateway;
use App\Services\Payment\Drivers\MidtransGateway;
use App\Services\Payment\PaymentManager;

/*
 * Payments a person settles (P2-E1-T2).
 *
 * The driver with nothing to call, which is exactly what has to be tested: the
 * instruction it returns is empty, and it refuses every callback — because a
 * payload claiming a bank transfer landed is either a mistake or an attempt to
 * mark an order paid without paying.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->order = Order::factory()->forBranch($this->branch)->create();
    $this->gateway = app(ManualGateway::class);
});

it('returns an instruction with nothing to act on', function () {
    $payment = Payment::factory()->forOrder($this->order)->create();

    $instruction = $this->gateway->charge($payment);

    expect($instruction->hasActionable())->toBeFalse()
        ->and($instruction->attributes())->toBe([])
        ->and($instruction->expiresAt)->toBeNull();
});

it('leaves the order deadline alone, since it has none of its own', function () {
    $payment = Payment::factory()->forOrder($this->order)->create();
    $deadline = now()->addMinutes(15);

    expect($this->gateway->charge($payment)->deadlineAgainst($deadline))->toBe($deadline);
});

it('has no provider to ask, so asking changes nothing', function () {
    $payment = Payment::factory()->forOrder($this->order)->create();

    $result = $this->gateway->inquire($payment);

    expect($result->accepted)->toBeTrue()
        ->and($result->changesState())->toBeFalse();
});

it('says a refund has to be made by a person', function () {
    $payment = Payment::factory()->forOrder($this->order)->success()->create();

    $result = $this->gateway->refund($payment, $payment->amount);

    expect($result->outcome)->toBe(RefundResult::OUTCOME_MANUAL)
        ->and($result->needsHuman())->toBeTrue()
        ->and($result->isAccepted())->toBeFalse()
        ->and($result->reason)->toContain('transfer balik');
});

it('refuses a refund larger than the payment', function () {
    $payment = Payment::factory()->forOrder($this->order)->success()->create();

    $this->gateway->refund($payment, $payment->amount + 1);
})->throws(PaymentFailedException::class, 'melebihi nominal pembayaran');

describe('resolving a driver (ADR-004)', function () {
    it('gives the manual driver for a manual method', function () {
        $method = PaymentMethod::factory()->create();

        expect(app(PaymentManager::class)->for($method))->toBeInstanceOf(ManualGateway::class);
    });

    it('gives the midtrans driver for a midtrans method', function () {
        $method = PaymentMethod::factory()->midtrans()->create();

        expect(app(PaymentManager::class)->for($method))->toBeInstanceOf(MidtransGateway::class);
    });

    it('resolves a payment by the method it points at now, not by its snapshot', function () {
        $method = PaymentMethod::factory()->create();
        $payment = Payment::factory()->forOrder($this->order)->forMethod($method)->create();

        $method->update(['driver' => PaymentDriver::Midtrans]);

        expect(app(PaymentManager::class)->forPayment($payment->fresh()))
            ->toBeInstanceOf(MidtransGateway::class);
    });

    it('refuses a driver the application cannot yet serve, in words', function () {
        app(PaymentManager::class)->driver(PaymentDriver::Xendit);
    })->throws(PaymentFailedException::class, 'Xendit belum tersedia');
});
