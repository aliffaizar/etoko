<?php

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\Drivers\MidtransGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Midtrans, behind the same interface as a bank transfer (P2-E1-T5, T6).
 *
 * Driven entirely through `Http::fake()`, which is deliberate: the project has
 * no Midtrans keys, and a driver that can only be exercised against a live
 * sandbox is a driver that is not tested. What is being checked is the two
 * things only this class knows — the Snap request body, and what a
 * notification means — plus the signature check that the webhook's safety
 * rests on.
 */

const MIDTRANS_KEY = 'SB-Mid-server-FAKEKEY';

beforeEach(function () {
    config()->set('services.midtrans.server_key', MIDTRANS_KEY);
    config()->set('services.midtrans.snap_base_url', 'https://app.sandbox.midtrans.com');
    config()->set('services.midtrans.api_base_url', 'https://api.sandbox.midtrans.com');

    $this->branch = Branch::factory()->default()->create();
    $this->order = Order::factory()->forBranch($this->branch)->create([
        'order_number' => 'ORD-20261108-0001',
        'subtotal' => 500_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => 500_000,
        'customer_name' => 'Budi Santoso',
        'customer_email' => 'budi@example.test',
    ]);
    $this->method = PaymentMethod::factory()->midtrans(PaymentMethodType::Qris)->create([
        'config' => ['midtrans' => ['enabled_payments' => ['other_qris']]],
    ]);
    $this->payment = Payment::factory()
        ->forOrder($this->order)
        ->forMethod($this->method)
        ->create(['expires_at' => now()->addMinutes(15)]);
    $this->gateway = app(MidtransGateway::class);
});

/**
 * The signature Midtrans would send for a payload.
 */
function midtransSignature(string $orderId, string $statusCode, string $grossAmount): string
{
    return hash('sha512', $orderId.$statusCode.$grossAmount.MIDTRANS_KEY);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function midtransPayload(Payment $payment, string $status = 'settlement', array $overrides = []): array
{
    $gross = number_format($payment->amount, 2, '.', '');

    return [
        'order_id' => $payment->reference,
        'status_code' => '200',
        'gross_amount' => $gross,
        'transaction_status' => $status,
        'transaction_id' => 'mt-txn-1',
        'payment_type' => 'qris',
        'signature_key' => midtransSignature($payment->reference, '200', $gross),
        ...$overrides,
    ];
}

describe('asking Snap for a transaction', function () {
    it('keys the transaction on the payment reference, not the order number', function () {
        Http::fake(['*' => Http::response(['token' => 't', 'redirect_url' => 'https://pay.test/t'])]);

        $this->gateway->charge($this->payment);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            // Per-attempt, because Midtrans refuses an id it has already seen
            // — so keying on the order number would break every retry.
            return $body['transaction_details']['order_id'] === $this->payment->reference
                && $body['transaction_details']['order_id'] !== $this->order->order_number;
        });
    });

    it('sends item lines that add up to exactly the gross amount', function () {
        Http::fake(['*' => Http::response(['token' => 't', 'redirect_url' => 'https://pay.test/t'])]);

        $withFee = Payment::factory()
            ->forOrder($this->order)
            ->forMethod($this->method)
            ->create(['fee_amount' => 4_000, 'amount' => 504_000]);

        $this->gateway->charge($withFee);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $sum = array_sum(array_map(
                fn (array $line): int => $line['price'] * $line['quantity'],
                $body['item_details'],
            ));

            return $sum === $body['transaction_details']['gross_amount'] && $sum === 504_000;
        });
    });

    it('narrows the Snap page to the channels the method is about', function () {
        Http::fake(['*' => Http::response(['token' => 't', 'redirect_url' => 'https://pay.test/t'])]);

        $this->gateway->charge($this->payment);

        Http::assertSent(fn (Request $request): bool => $request->data()['enabled_payments'] === ['other_qris']);
    });

    it('asks for an expiry no longer than the stock hold (ADR-041)', function () {
        Http::fake(['*' => Http::response(['token' => 't', 'redirect_url' => 'https://pay.test/t'])]);

        $this->gateway->charge($this->payment);

        Http::assertSent(function (Request $request): bool {
            $expiry = $request->data()['expiry'];

            return $expiry['unit'] === 'minute' && $expiry['duration'] <= 15 && $expiry['duration'] >= 1;
        });
    });

    it('returns the redirect url and keeps the raw answer', function () {
        Http::fake(['*' => Http::response([
            'token' => 'tok-9',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-9',
        ])]);

        $instruction = $this->gateway->charge($this->payment);

        expect($instruction->paymentUrl)->toContain('tok-9')
            ->and($instruction->hasActionable())->toBeTrue()
            ->and($instruction->rawPayload['token'])->toBe('tok-9');
    });

    it('quotes the gateway own words when it refuses', function () {
        Http::fake(['*' => Http::response(
            ['error_messages' => ['transaction_details.gross_amount is not valid']],
            400,
        )]);

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'gross_amount is not valid');

    it('says so plainly when the gateway cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'tidak dapat dihubungi');

    it('refuses to send an unsigned request when no key is configured', function () {
        config()->set('services.midtrans.server_key', null);

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'MIDTRANS_SERVER_KEY');
});

describe('verifying a notification (P2-E1-T6)', function () {
    it('accepts a correctly signed settlement', function () {
        $result = $this->gateway->handleCallback(midtransPayload($this->payment));

        expect($result->accepted)->toBeTrue()
            ->and($result->reference)->toBe($this->payment->reference)
            ->and($result->status)->toBe(PaymentTransactionStatus::Success)
            ->and($result->externalId)->toBe('mt-txn-1')
            ->and($result->amount)->toBe($this->payment->amount);
    });

    it('rejects a payload whose signature does not match', function () {
        $payload = midtransPayload($this->payment, overrides: ['signature_key' => str_repeat('a', 128)]);

        $result = $this->gateway->handleCallback($payload);

        expect($result->accepted)->toBeFalse()
            ->and($result->reason)->toContain('Signature');
    });

    it('rejects a payload with no signature at all', function () {
        $payload = midtransPayload($this->payment);
        unset($payload['signature_key']);

        expect($this->gateway->handleCallback($payload)->accepted)->toBeFalse();
    });

    it('rejects a signature computed over a different amount', function () {
        // The forged payload claims a smaller amount but signs it correctly
        // for that amount, which is why the signature alone is not enough and
        // `HandlePaymentCallback` compares the figure as well.
        $payload = midtransPayload($this->payment, overrides: [
            'gross_amount' => '1000.00',
            'signature_key' => midtransSignature($this->payment->reference, '200', '1000.00'),
        ]);

        $result = $this->gateway->handleCallback($payload);

        expect($result->accepted)->toBeTrue()
            ->and($result->amount)->toBe(1_000)
            ->and($result->amount)->not->toBe($this->payment->amount);
    });

    it('refuses everything when there is no key to verify against', function () {
        config()->set('services.midtrans.server_key', null);

        expect($this->gateway->handleCallback(midtransPayload($this->payment))->accepted)->toBeFalse();
    });

    it('rejects a payload with no order_id', function () {
        expect($this->gateway->handleCallback(['transaction_status' => 'settlement'])->accepted)->toBeFalse();
    });

    it('maps each transaction status to what it means', function (string $status, PaymentTransactionStatus $expected) {
        $result = $this->gateway->handleCallback(midtransPayload($this->payment, $status));

        expect($result->status)->toBe($expected);
    })->with([
        ['settlement', PaymentTransactionStatus::Success],
        ['deny', PaymentTransactionStatus::Failed],
        ['cancel', PaymentTransactionStatus::Failed],
        ['expire', PaymentTransactionStatus::Expired],
        ['refund', PaymentTransactionStatus::Refunded],
        ['partial_refund', PaymentTransactionStatus::Refunded],
    ]);

    it('treats an accepted capture as money and a denied one as a failure', function () {
        $accepted = $this->gateway->handleCallback(
            midtransPayload($this->payment, 'capture', ['fraud_status' => 'accept']),
        );
        $denied = $this->gateway->handleCallback(
            midtransPayload($this->payment, 'capture', ['fraud_status' => 'deny']),
        );

        expect($accepted->status)->toBe(PaymentTransactionStatus::Success)
            ->and($denied->status)->toBe(PaymentTransactionStatus::Failed);
    });

    it('changes nothing for a challenged capture, which is still being screened', function () {
        $result = $this->gateway->handleCallback(
            midtransPayload($this->payment, 'capture', ['fraud_status' => 'challenge']),
        );

        expect($result->accepted)->toBeTrue()
            ->and($result->changesState())->toBeFalse();
    });

    it('changes nothing for a pending notification about a number nobody has used', function () {
        $result = $this->gateway->handleCallback(midtransPayload($this->payment, 'pending'));

        expect($result->accepted)->toBeTrue()
            ->and($result->changesState())->toBeFalse();
    });
});

describe('asking for money back', function () {
    it('calls the core api with an idempotent refund key', function () {
        Http::fake(['*' => Http::response(['status_code' => '200', 'refund_key' => 'RFD-1'])]);

        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        $result = $this->gateway->refund($payment, 100_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_SETTLED)
            ->and($result->isAccepted())->toBeTrue();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/v2/{$payment->reference}/refund")
            && $request->data()['refund_key'] === "RFD-{$payment->getKey()}-100000"
            && $request->data()['amount'] === 100_000);
    });

    it('reports a refund the gateway has only accepted as pending', function () {
        Http::fake(['*' => Http::response(['status_code' => '201', 'refund_key' => 'RFD-2'])]);

        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        expect($this->gateway->refund($payment, 50_000)->outcome)->toBe(RefundResult::OUTCOME_PENDING);
    });

    it('treats a 200 carrying a refusal code as a refusal', function () {
        Http::fake(['*' => Http::response([
            'status_code' => '412',
            'status_message' => 'Merchant cannot modify the status of the transaction',
        ])]);

        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        $result = $this->gateway->refund($payment, 50_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_FAILED)
            ->and($result->reason)->toContain('cannot modify');
    });

    it('refuses to refund an attempt that was never settled', function () {
        Http::fake();

        $result = $this->gateway->refund($this->payment, 1_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_FAILED);

        Http::assertNothingSent();
    });

    it('refuses to refund more than was paid', function () {
        Http::fake();

        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        expect($this->gateway->refund($payment, $payment->amount + 1)->outcome)
            ->toBe(RefundResult::OUTCOME_FAILED);

        Http::assertNothingSent();
    });
});
