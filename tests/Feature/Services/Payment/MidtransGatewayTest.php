<?php

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\MidtransApiException;
use App\Exceptions\PaymentFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\Drivers\MidtransGateway;

/*
 * Midtrans, behind the same interface as a bank transfer (P2-E1-T5, T6,
 * ADR-053).
 *
 * Driven entirely through `fakeMidtrans()`, which stands in for the SDK
 * client: a driver that can only be exercised against a live sandbox is a
 * driver that is not tested. What is being checked is the two things only
 * this class knows — the Core API charge body for each channel, and what an
 * answer means.
 */

beforeEach(function () {
    config()->set('services.midtrans.server_key', 'SB-Mid-server-FAKEKEY');
    // The default follows the environment (sandbox or production), so it is
    // pinned here rather than inherited from whoever runs the suite.
    config()->set('services.midtrans.qris_acquirer', 'gopay');

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
    $this->method = PaymentMethod::factory()->midtrans(PaymentMethodType::VirtualAccount)->create();
    $this->payment = Payment::factory()
        ->forOrder($this->order)
        ->forMethod($this->method)
        ->create(['expires_at' => now()->addDay()]);
    $this->midtrans = fakeMidtrans();
    $this->gateway = app(MidtransGateway::class);
});

/**
 * A pending attempt against a Midtrans method of the given kind.
 */
function midtransAttempt(PaymentMethodType $type, ?string $providerCode = null): Payment
{
    $method = PaymentMethod::factory()->midtrans($type)->create(
        $providerCode === null ? [] : ['provider_code' => $providerCode],
    );

    return Payment::factory()
        ->forOrder(test()->order)
        ->forMethod($method)
        ->create(['expires_at' => now()->addDay()]);
}

/**
 * What the Core API answers a virtual account charge with.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function midtransChargeAnswer(Payment $payment, array $overrides = []): array
{
    return [
        'status_code' => '201',
        'status_message' => 'Success, Bank Transfer transaction is created',
        'transaction_id' => 'mt-txn-1',
        'order_id' => $payment->reference,
        'gross_amount' => number_format($payment->amount, 2, '.', ''),
        'payment_type' => 'bank_transfer',
        'transaction_status' => 'pending',
        'va_numbers' => [['bank' => 'bca', 'va_number' => '81234567890']],
        ...$overrides,
    ];
}

describe('charging through the Core API', function () {
    it('keys the transaction on the payment reference, not the order number', function () {
        $this->midtrans->chargeAnswers(midtransChargeAnswer($this->payment));

        $this->gateway->charge($this->payment);

        // Per-attempt, because Midtrans refuses an id it has already seen
        // — so keying on the order number would break every retry.
        expect($this->midtrans->charges[0]['transaction_details']['order_id'])
            ->toBe($this->payment->reference)
            ->not->toBe($this->order->order_number);
    });

    it('sends item lines that add up to exactly the gross amount', function () {
        $withFee = Payment::factory()
            ->forOrder($this->order)
            ->forMethod($this->method)
            ->create(['fee_amount' => 4_000, 'amount' => 504_000]);
        $this->midtrans->chargeAnswers(midtransChargeAnswer($withFee));

        $this->gateway->charge($withFee);

        $body = $this->midtrans->charges[0];
        $sum = array_sum(array_map(
            fn (array $line): int => $line['price'] * $line['quantity'],
            $body['item_details'],
        ));

        expect($sum)->toBe(504_000)
            ->and($body['transaction_details']['gross_amount'])->toBe(504_000);
    });

    it('asks for the channel the method is about', function (PaymentMethodType $type, ?string $provider, string $paymentType, array $detail) {
        $payment = midtransAttempt($type, $provider);
        $this->midtrans->chargeAnswers(midtransChargeAnswer($payment));

        $this->gateway->charge($payment);

        $body = $this->midtrans->charges[0];

        expect($body['payment_type'])->toBe($paymentType)
            ->and($body[$paymentType])->toMatchArray($detail);
    })->with([
        'BCA virtual account' => [PaymentMethodType::VirtualAccount, 'bca', 'bank_transfer', ['bank' => 'bca']],
        'BNI virtual account' => [PaymentMethodType::VirtualAccount, 'bni', 'bank_transfer', ['bank' => 'bni']],
        'QRIS' => [PaymentMethodType::Qris, null, 'qris', ['acquirer' => 'gopay']],
        'GoPay' => [PaymentMethodType::EWallet, 'gopay', 'gopay', ['enable_callback' => true]],
    ]);

    it('issues QRIS through the acquirer the Midtrans account is set up with', function () {
        config()->set('services.midtrans.qris_acquirer', 'airpay shopee');
        $payment = midtransAttempt(PaymentMethodType::Qris);
        $this->midtrans->chargeAnswers(midtransChargeAnswer($payment));

        $this->gateway->charge($payment);

        expect($this->midtrans->charges[0]['qris'])->toBe(['acquirer' => 'airpay shopee']);
    });

    it('tells an e-wallet to send the customer back to the shop', function () {
        $payment = midtransAttempt(PaymentMethodType::EWallet, 'shopeepay');
        $this->midtrans->chargeAnswers(midtransChargeAnswer($payment));

        $this->gateway->charge($payment);

        expect($this->midtrans->charges[0]['shopeepay']['callback_url'])
            ->toBe(route('shop.payments.return', ['order_id' => $payment->reference]));
    });

    it('refuses a channel it cannot translate instead of guessing one', function () {
        $payment = midtransAttempt(PaymentMethodType::VirtualAccount, 'mandiri');

        expect(fn () => $this->gateway->charge($payment))
            ->toThrow(PaymentFailedException::class, 'belum dapat diproses lewat Midtrans')
            ->and($this->midtrans->charges)->toBe([]);
    });

    it('asks for an expiry no longer than the stock hold (ADR-041)', function () {
        $this->freezeTime();
        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)
            ->create(['expires_at' => now()->addMinutes(90)->addSeconds(40)]);
        $this->midtrans->chargeAnswers(midtransChargeAnswer($payment));

        $this->gateway->charge($payment);

        // Rounded down: Midtrans must close no later than the hold does.
        expect($this->midtrans->charges[0]['custom_expiry'])
            ->toBe(['expiry_duration' => 90, 'unit' => 'minute']);
    });

    it('returns the virtual account number and keeps the raw answer', function () {
        $this->midtrans->chargeAnswers(midtransChargeAnswer($this->payment));

        $instruction = $this->gateway->charge($this->payment);

        expect($instruction->vaNumber)->toBe('81234567890')
            ->and($instruction->externalId)->toBe('mt-txn-1')
            ->and($instruction->hasActionable())->toBeTrue()
            ->and($instruction->rawPayload['transaction_status'])->toBe('pending');
    });

    it('reads the Permata number from the field Permata answers in', function () {
        $payment = midtransAttempt(PaymentMethodType::VirtualAccount, 'permata');
        $answer = midtransChargeAnswer($payment, ['permata_va_number' => '8778000111222']);
        unset($answer['va_numbers']);
        $this->midtrans->chargeAnswers($answer);

        expect($this->gateway->charge($payment)->vaNumber)->toBe('8778000111222');
    });

    it('returns the QR string and the image Midtrans rendered for QRIS', function () {
        $payment = midtransAttempt(PaymentMethodType::Qris);
        $answer = midtransChargeAnswer($payment, [
            'payment_type' => 'qris',
            'qr_string' => '00020101021226620014COM.GO-JEK.WWW',
            'actions' => [[
                'name' => 'generate-qr-code-v2',
                'method' => 'GET',
                'url' => 'https://api.sandbox.midtrans.com/v2/qris/mt-txn-1/qr-code',
            ]],
        ]);
        unset($answer['va_numbers']);
        $this->midtrans->chargeAnswers($answer);

        $instruction = $this->gateway->charge($payment);

        expect($instruction->qrString)->toBe('00020101021226620014COM.GO-JEK.WWW')
            ->and(MidtransGateway::qrImageUrl($instruction->rawPayload))
            ->toBe('https://api.sandbox.midtrans.com/v2/qris/mt-txn-1/qr-code');
    });

    it('returns the deeplink that opens the wallet app for GoPay', function () {
        $payment = midtransAttempt(PaymentMethodType::EWallet, 'gopay');
        $answer = midtransChargeAnswer($payment, [
            'payment_type' => 'gopay',
            'actions' => [
                ['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/gopay/mt-txn-1/qr-code'],
                ['name' => 'deeplink-redirect', 'method' => 'GET', 'url' => 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=1'],
            ],
        ]);
        unset($answer['va_numbers']);
        $this->midtrans->chargeAnswers($answer);

        expect($this->gateway->charge($payment)->paymentUrl)->toContain('payment-pin');
    });

    it('believes Midtrans when it closes the transaction sooner than asked', function () {
        $this->midtrans->chargeAnswers(midtransChargeAnswer($this->payment, [
            'expiry_time' => now()->addHours(2)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
        ]));

        $instruction = $this->gateway->charge($this->payment);

        expect($instruction->deadlineAgainst($this->payment->expires_at)->toDateTimeString())
            ->toBe(now()->addHours(2)->toDateTimeString());
    });

    it('quotes the gateway own words when it refuses', function () {
        $this->midtrans->chargeAnswers(MidtransApiException::refused(400, [
            'status_code' => '400',
            'validation_messages' => ['transaction_details.gross_amount is not valid'],
        ]));

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'gross_amount is not valid');

    it('treats an answer with nothing to pay with as a refusal', function () {
        $answer = midtransChargeAnswer($this->payment);
        unset($answer['va_numbers']);
        $this->midtrans->chargeAnswers($answer);

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'tidak memuat instruksi');

    it('says so plainly when the gateway cannot be reached', function () {
        $this->midtrans->chargeAnswers(MidtransApiException::unreachable());

        $this->gateway->charge($this->payment);
    })->throws(PaymentFailedException::class, 'tidak dapat dihubungi');

    it('refuses to call Midtrans when no key is configured', function () {
        config()->set('services.midtrans.server_key', null);

        expect(fn () => $this->gateway->charge($this->payment))
            ->toThrow(PaymentFailedException::class, 'MIDTRANS_SERVER_KEY')
            ->and($this->midtrans->charges)->toBe([]);
    });
});

describe('asking Midtrans what became of a payment (ADR-052, ADR-053)', function () {
    it('reads a settlement from the status api for the payment reference', function () {
        $this->midtrans->statusAnswers(midtransStatusAnswer($this->payment));

        $result = $this->gateway->inquire($this->payment);

        expect($result->status)->toBe(PaymentTransactionStatus::Success)
            ->and($result->reference)->toBe($this->payment->reference)
            ->and($result->externalId)->toBe('mt-status-1')
            ->and($result->amount)->toBe($this->payment->amount)
            ->and($this->midtrans->statusChecks)->toBe([$this->payment->reference]);
    });

    it('maps each transaction status to what it means', function (string $status, PaymentTransactionStatus $expected) {
        $this->midtrans->statusAnswers(midtransStatusAnswer($this->payment, $status));

        expect($this->gateway->inquire($this->payment)->status)->toBe($expected);
    })->with([
        ['settlement', PaymentTransactionStatus::Success],
        ['deny', PaymentTransactionStatus::Failed],
        ['cancel', PaymentTransactionStatus::Failed],
        ['expire', PaymentTransactionStatus::Expired],
        ['refund', PaymentTransactionStatus::Refunded],
        ['partial_refund', PaymentTransactionStatus::Refunded],
    ]);

    it('treats an accepted capture as money and a denied one as a failure', function () {
        $this->midtrans->statusAnswers([...midtransStatusAnswer($this->payment, 'capture'), 'fraud_status' => 'accept']);
        $accepted = $this->gateway->inquire($this->payment);

        $this->midtrans->statusAnswers([...midtransStatusAnswer($this->payment, 'capture'), 'fraud_status' => 'deny']);
        $denied = $this->gateway->inquire($this->payment);

        expect($accepted->status)->toBe(PaymentTransactionStatus::Success)
            ->and($denied->status)->toBe(PaymentTransactionStatus::Failed);
    });

    it('changes nothing for a challenged capture, which is still being screened', function () {
        $this->midtrans->statusAnswers([...midtransStatusAnswer($this->payment, 'capture'), 'fraud_status' => 'challenge']);

        $result = $this->gateway->inquire($this->payment);

        expect($result->accepted)->toBeTrue()
            ->and($result->changesState())->toBeFalse();
    });

    it('reports nothing yet when Midtrans has no transaction for the reference', function () {
        $this->midtrans->statusAnswers(MidtransApiException::refused(404, [
            'status_code' => '404',
            'status_message' => "Transaction doesn't exist.",
        ]));

        $result = $this->gateway->inquire($this->payment);

        expect($result->accepted)->toBeTrue()
            ->and($result->changesState())->toBeFalse();
    });

    it('reports nothing yet while the transaction is still pending', function () {
        $this->midtrans->statusAnswers(midtransStatusAnswer($this->payment, 'pending'));

        expect($this->gateway->inquire($this->payment)->changesState())->toBeFalse();
    });

    it('refuses an answer about a different transaction', function () {
        $this->midtrans->statusAnswers([
            ...midtransStatusAnswer($this->payment),
            'order_id' => 'ORD-20261108-0009-SOMEBODY',
        ]);

        $this->gateway->inquire($this->payment);
    })->throws(PaymentFailedException::class);

    it('throws when Midtrans cannot be reached, so unknown is never read as unpaid', function () {
        $this->midtrans->statusAnswers(MidtransApiException::unreachable());

        $this->gateway->inquire($this->payment);
    })->throws(PaymentFailedException::class, 'tidak dapat dihubungi');

    it('throws when Midtrans answers with a server error', function () {
        $this->midtrans->statusAnswers(MidtransApiException::refused(500));

        $this->gateway->inquire($this->payment);
    })->throws(PaymentFailedException::class);
});

describe('asking for money back', function () {
    it('calls the core api with an idempotent refund key', function () {
        $this->midtrans->refundAnswers(['status_code' => '200', 'refund_key' => 'RFD-1']);
        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        $result = $this->gateway->refund($payment, 100_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_SETTLED)
            ->and($result->isAccepted())->toBeTrue()
            ->and($this->midtrans->refunds[0]['reference'])->toBe($payment->reference)
            ->and($this->midtrans->refunds[0]['params'])->toMatchArray([
                'refund_key' => "RFD-{$payment->getKey()}-100000",
                'amount' => 100_000,
            ]);
    });

    it('reports a refund the gateway has only accepted as pending', function () {
        $this->midtrans->refundAnswers(['status_code' => '201', 'refund_key' => 'RFD-2']);
        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        expect($this->gateway->refund($payment, 50_000)->outcome)->toBe(RefundResult::OUTCOME_PENDING);
    });

    it('reports a refusal in the gateway own words', function () {
        $this->midtrans->refundAnswers(MidtransApiException::refused(412, [
            'status_code' => '412',
            'status_message' => 'Merchant cannot modify the status of the transaction',
        ]));
        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        $result = $this->gateway->refund($payment, 50_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_FAILED)
            ->and($result->reason)->toContain('cannot modify');
    });

    it('refuses to refund an attempt that was never settled', function () {
        $result = $this->gateway->refund($this->payment, 1_000);

        expect($result->outcome)->toBe(RefundResult::OUTCOME_FAILED);

        expect($this->midtrans->refunds)->toBe([]);
    });

    it('refuses to refund more than was paid', function () {
        $payment = Payment::factory()->forOrder($this->order)->forMethod($this->method)->success()->create();

        expect($this->gateway->refund($payment, $payment->amount + 1)->outcome)
            ->toBe(RefundResult::OUTCOME_FAILED);

        expect($this->midtrans->refunds)->toBe([]);
    });
});
