<?php

namespace App\Services\Payment\Drivers;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\MidtransApiException;
use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\Data\PaymentStatusResult;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\Midtrans\MidtransClient;
use App\Services\Payment\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Midtrans, behind the same interface as a bank transfer (P2-E1-T5, ADR-004,
 * ADR-053).
 *
 * **Core API, not Snap.** The customer is never sent to a page of Midtrans':
 * `POST /v2/charge` issues the virtual account number, the QRIS code or the
 * e-wallet link itself, and the shop's own order page shows it. That is one
 * request body per kind of channel, which is the price — `channelFor()` is
 * where it is paid, and the only place that knows a channel's vocabulary.
 *
 * **No webhook, and so no signature.** The status of a transaction reaches
 * the application one way: `inquire()` asks the Status API, with the server
 * key, about a reference we issued. Nothing Midtrans sends is listened for.
 *
 * Every call goes through `MidtransClient`, which wraps the official SDK.
 * Nothing here is written to the database: this is a driver (see
 * `PaymentGateway`). What `charge()` returns is persisted by
 * `InitiatePayment`, inside its transaction.
 *
 * **`transaction_details.order_id` is the payment's `reference`, not the order
 * number.** Midtrans refuses a second transaction under an id it has seen, so
 * keying on the order number would make every retry of a failed attempt fail
 * too; and the Status API is asked by that id, so a per-attempt reference is
 * what lets an answer be matched to exactly one row.
 */
final class MidtransGateway implements PaymentGateway
{
    /**
     * The `transaction_status` values Midtrans answers with, and what each one
     * means to us.
     *
     * `capture` and `pending` are absent on purpose and handled below:
     * `capture` depends on `fraud_status`, and `pending` means the customer
     * has been given a number and has not used it — which is the state the
     * payment is already in, so acting on it would be writing the same row for
     * nothing.
     *
     * @var array<string, PaymentTransactionStatus>
     */
    private const STATUS_MAP = [
        'settlement' => PaymentTransactionStatus::Success,
        'deny' => PaymentTransactionStatus::Failed,
        'cancel' => PaymentTransactionStatus::Failed,
        'failure' => PaymentTransactionStatus::Failed,
        'expire' => PaymentTransactionStatus::Expired,
        'refund' => PaymentTransactionStatus::Refunded,
        'partial_refund' => PaymentTransactionStatus::Refunded,
    ];

    /**
     * The banks a virtual account can be charged through `bank_transfer`.
     *
     * Mandiri is absent because it is not a `bank_transfer` at all: Midtrans
     * issues it as `echannel`, a bill key plus a biller code, which is a
     * different thing for the customer to type and needs its own instructions.
     *
     * @var array<int, string>
     */
    private const VIRTUAL_ACCOUNT_BANKS = ['bca', 'bni', 'bri', 'permata', 'cimb'];

    /**
     * The timezone Midtrans writes `expiry_time` in, which carries no offset.
     */
    private const MIDTRANS_TIMEZONE = 'Asia/Jakarta';

    public function __construct(private readonly MidtransClient $client) {}

    /**
     * Charge through the Core API and return what the customer must do.
     *
     * @throws PaymentFailedException
     */
    public function charge(Payment $payment): PaymentInstruction
    {
        $this->assertConfigured();

        $params = $this->chargeBody($payment);

        try {
            $body = $this->client->charge($params);
        } catch (MidtransApiException $failure) {
            throw $this->asPaymentFailure($failure);
        }

        // Midtrans answers a charge it took with 201 ("pending") and puts a
        // refusal in the same field, sometimes under an HTTP 200.
        if (! in_array($this->stringFrom($body, 'status_code'), ['200', '201'], strict: true)) {
            throw PaymentFailedException::gatewayRefused($this->errorDetail($body));
        }

        $instruction = new PaymentInstruction(
            externalId: $this->stringFrom($body, 'transaction_id'),
            vaNumber: $this->virtualAccountNumberFrom($body),
            qrString: $this->stringFrom($body, 'qr_string'),
            paymentUrl: self::actionUrl($body, 'deeplink-redirect'),
            expiresAt: $this->expiryFrom($body),
            rawPayload: $body,
        );

        if (! $instruction->hasActionable() && self::qrImageUrl($body) === null) {
            throw PaymentFailedException::gatewayRefused('balasan Midtrans tidak memuat instruksi pembayaran.');
        }

        return $instruction;
    }

    /**
     * Ask the Core API for the transaction's status (`GET /v2/{order_id}/status`).
     *
     * The answer is trusted because it is the reply to a request we made over
     * TLS with the server key. It is still required to name the reference that
     * was asked about, so an answer about a different transaction is never
     * applied.
     *
     * A 404 is reported as "nothing yet" rather than as a failure: it means
     * Midtrans holds no transaction under that reference, which is true of an
     * attempt whose charge never completed.
     */
    public function inquire(Payment $payment): PaymentStatusResult
    {
        $this->assertConfigured();

        try {
            $body = $this->client->status($payment->reference);
        } catch (MidtransApiException $failure) {
            if ($failure->isNotFound()) {
                return PaymentStatusResult::ignored(
                    $payment->reference,
                    'Midtrans belum mencatat transaksi untuk pembayaran ini.',
                    $failure->body,
                );
            }

            throw $this->asPaymentFailure($failure);
        }

        if ($this->stringFrom($body, 'order_id') !== $payment->reference) {
            throw PaymentFailedException::gatewayRefused($this->errorDetail($body));
        }

        return $this->resultFor($payment->reference, $body);
    }

    /**
     * Ask the Core API to send money back.
     *
     * `refund_key` is derived from the payment and the amount so that the same
     * request sent twice is one refund at Midtrans' end rather than two —
     * their own idempotency handle, used for what it is for.
     */
    public function refund(Payment $payment, int $amount): RefundResult
    {
        if ($payment->status !== PaymentTransactionStatus::Success) {
            return RefundResult::failed(
                $amount,
                "Pembayaran berstatus \"{$payment->status->label()}\" tidak dapat direfund.",
            );
        }

        if ($amount > $payment->amount) {
            return RefundResult::failed($amount, 'Nominal pengembalian melebihi nominal pembayaran.');
        }

        try {
            $this->assertConfigured();

            $body = $this->client->refund($payment->reference, [
                'refund_key' => "RFD-{$payment->getKey()}-{$amount}",
                'amount' => $amount,
                'reason' => "Refund pesanan {$payment->order->order_number}",
            ]);
        } catch (MidtransApiException $failure) {
            return RefundResult::failed($amount, $this->asPaymentFailure($failure)->getMessage());
        } catch (PaymentFailedException $unconfigured) {
            return RefundResult::failed($amount, $unconfigured->getMessage());
        }

        $statusCode = $this->stringFrom($body, 'status_code');

        // Midtrans answers a refund with its own status code rather than with
        // the HTTP status, so a 200 carrying `412` is a refusal.
        if (! in_array($statusCode, ['200', '201', '202'], strict: true)) {
            return RefundResult::failed($amount, $this->errorDetail($body));
        }

        $externalId = $this->stringFrom($body, 'refund_key') ?? $this->stringFrom($body, 'transaction_id');

        return $statusCode === '200'
            ? RefundResult::settled($amount, $externalId)
            : RefundResult::pending($amount, $externalId);
    }

    /**
     * The address of the QR image Midtrans renders for a charge, when the
     * channel has one (QRIS and GoPay).
     *
     * Read from the charge answer rather than stored in a column of its own:
     * it is only ever needed while the attempt is open, and the answer is
     * what `payments.raw_payload` holds until the attempt closes. Showing
     * Midtrans' own image also spares the storefront a QR-rendering library.
     *
     * @param  array<string, mixed>|null  $chargeAnswer
     */
    public static function qrImageUrl(?array $chargeAnswer): ?string
    {
        return self::actionUrl($chargeAnswer ?? [], 'generate-qr-code');
    }

    /**
     * The Core API charge body.
     *
     * `item_details` is sent so the customer's banking or wallet app names
     * what is being paid for, and `gross_amount` must equal the sum of the
     * lines or Midtrans refuses the transaction — so the method's fee is sent
     * as a line of its own rather than folded into a price.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentFailedException
     */
    private function chargeBody(Payment $payment): array
    {
        $order = $payment->order;

        $body = [
            ...$this->channelFor($payment),
            'transaction_details' => [
                'order_id' => $payment->reference,
                'gross_amount' => $payment->amount,
            ],
            'customer_details' => array_filter([
                'first_name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'item_details' => $this->itemDetails($payment),
        ];

        $minutes = $this->minutesUntil($payment->expires_at);

        if ($minutes !== null) {
            $body['custom_expiry'] = ['expiry_duration' => $minutes, 'unit' => 'minute'];
        }

        return $body;
    }

    /**
     * The part of the charge body that says *how* the money is collected.
     *
     * Decided by the method's `type` and narrowed by its `provider_code` — the
     * bank for a virtual account, the wallet for an e-wallet. A method this
     * cannot translate is refused in words rather than sent to Midtrans as a
     * guess, because the default `payment_type` of a charge is a credit card.
     *
     * QRIS is issued through whichever acquirer the Midtrans account has
     * switched on, which is the account's fact rather than the method's — so
     * it is a setting, and an account without that acquirer is refused by
     * Midtrans in its own words.
     *
     * The e-wallets are told where to send the customer back to: they pay in
     * another app, and without a callback they are left there.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentFailedException
     */
    private function channelFor(Payment $payment): array
    {
        $method = $payment->paymentMethod;
        $provider = mb_strtolower((string) $method->provider_code);
        $returnUrl = route('shop.payments.return', ['order_id' => $payment->reference]);

        return match (true) {
            $method->type === PaymentMethodType::VirtualAccount
                && in_array($provider, self::VIRTUAL_ACCOUNT_BANKS, strict: true) => [
                    'payment_type' => 'bank_transfer',
                    'bank_transfer' => ['bank' => $provider],
                ],
            $method->type === PaymentMethodType::Qris => [
                'payment_type' => 'qris',
                'qris' => ['acquirer' => (string) config('services.midtrans.qris_acquirer', 'gopay')],
            ],
            $method->type === PaymentMethodType::EWallet && $provider === 'gopay' => [
                'payment_type' => 'gopay',
                'gopay' => ['enable_callback' => true, 'callback_url' => $returnUrl],
            ],
            $method->type === PaymentMethodType::EWallet && $provider === 'shopeepay' => [
                'payment_type' => 'shopeepay',
                'shopeepay' => ['callback_url' => $returnUrl],
            ],
            default => throw PaymentFailedException::unsupported(
                "Metode pembayaran {$method->name} belum dapat diproses lewat Midtrans. Pilih metode pembayaran lain.",
            ),
        };
    }

    /**
     * The lines Midtrans is sent, adding up to exactly `gross_amount`.
     *
     * The order's own items are **not** itemised here: discounts, shipping and
     * tax would each need a line of their own and the total would have to be
     * reconciled against `grand_total` a second way. One line for the order
     * plus one for the method's fee is both honest and arithmetically safe.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemDetails(Payment $payment): array
    {
        $order = $payment->order;

        $lines = [[
            'id' => $order->order_number,
            'name' => mb_substr("Pesanan {$order->order_number}", 0, 50),
            'price' => $order->grand_total,
            'quantity' => 1,
        ]];

        if ($payment->fee_amount > 0) {
            $lines[] = [
                'id' => 'fee',
                'name' => mb_substr("Biaya {$payment->method_name}", 0, 50),
                'price' => $payment->fee_amount,
                'quantity' => 1,
            ];
        }

        return $lines;
    }

    /**
     * What a status answer says about a payment.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resultFor(string $reference, array $payload): PaymentStatusResult
    {
        $transactionStatus = $this->stringFrom($payload, 'transaction_status');

        if ($transactionStatus === null) {
            return PaymentStatusResult::rejected('Payload tidak memuat transaction_status.', $payload);
        }

        $status = $this->statusFor($transactionStatus, $this->stringFrom($payload, 'fraud_status'));

        if (! $status instanceof PaymentTransactionStatus) {
            return PaymentStatusResult::ignored(
                $reference,
                "Status \"{$transactionStatus}\" belum mengubah apa pun pada pembayaran.",
                $payload,
            );
        }

        return PaymentStatusResult::settled(
            reference: $reference,
            status: $status,
            externalId: $this->stringFrom($payload, 'transaction_id'),
            amount: $this->amountFrom($payload),
            payload: $payload,
        );
    }

    /**
     * Whole minutes from now until the deadline, or null when there is none.
     *
     * Rounded **down**, so Midtrans closes the transaction no later than the
     * stock hold does: rounding up left a minute in which Midtrans would take
     * money for an order the sweep had already expired (`PAY-06`). At least
     * one, because Midtrans rejects a zero duration.
     */
    private function minutesUntil(?CarbonInterface $deadline): ?int
    {
        if ($deadline === null) {
            return null;
        }

        return max(1, (int) floor(now()->diffInMinutes($deadline, absolute: false)));
    }

    /**
     * When Midtrans says the transaction closes, or null when it does not say.
     *
     * `InitiatePayment` keeps the earlier of this and the order's own deadline
     * (ADR-041), so a channel that closes sooner than was asked is believed.
     *
     * @param  array<string, mixed>  $body
     */
    private function expiryFrom(array $body): ?CarbonInterface
    {
        $expiry = $this->stringFrom($body, 'expiry_time');

        if ($expiry === null) {
            return null;
        }

        try {
            return Carbon::parse($expiry, self::MIDTRANS_TIMEZONE)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The number the customer transfers to.
     *
     * Permata answers in a field of its own; every other bank answers in the
     * `va_numbers` list.
     *
     * @param  array<string, mixed>  $body
     */
    private function virtualAccountNumberFrom(array $body): ?string
    {
        $numbers = $body['va_numbers'] ?? null;

        if (is_array($numbers) && is_array($numbers[0] ?? null)) {
            return $this->stringFrom($numbers[0], 'va_number');
        }

        return $this->stringFrom($body, 'permata_va_number');
    }

    /**
     * The URL of one of the `actions` a charge answer lists, by the start of
     * its name — Midtrans has versioned them (`generate-qr-code-v2`).
     *
     * @param  array<string, mixed>  $body
     */
    private static function actionUrl(array $body, string $name): ?string
    {
        $actions = $body['actions'] ?? null;

        if (! is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (is_array($action)
                && is_string($action['name'] ?? null)
                && str_starts_with($action['name'], $name)
                && is_string($action['url'] ?? null)
                && $action['url'] !== '') {
                return $action['url'];
            }
        }

        return null;
    }

    /**
     * What a `transaction_status` means, or null when it means nothing yet.
     *
     * `capture` is the card flow and depends on the fraud screening: accepted
     * money is money, a challenged capture is money Midtrans is still thinking
     * about and must not take goods off the shelf, and a denied one is a
     * failure.
     */
    private function statusFor(string $transactionStatus, ?string $fraudStatus): ?PaymentTransactionStatus
    {
        if ($transactionStatus === 'capture') {
            return match ($fraudStatus) {
                'accept', null => PaymentTransactionStatus::Success,
                'deny' => PaymentTransactionStatus::Failed,
                default => null,
            };
        }

        return self::STATUS_MAP[$transactionStatus] ?? null;
    }

    /**
     * Refuse, in words, when there is no server key to call Midtrans with.
     *
     * @throws PaymentFailedException
     */
    private function assertConfigured(): void
    {
        $key = config('services.midtrans.server_key');

        if (! is_string($key) || $key === '') {
            throw PaymentFailedException::unsupported(
                'Pembayaran Midtrans belum dikonfigurasi (MIDTRANS_SERVER_KEY kosong). Pilih metode pembayaran lain.',
            );
        }
    }

    /**
     * A failed Midtrans call as the sentence a customer or a log reads.
     */
    private function asPaymentFailure(MidtransApiException $failure): PaymentFailedException
    {
        if ($failure->isUnreachable()) {
            Log::warning('Midtrans could not be reached.', ['message' => $failure->getPrevious()?->getMessage()]);

            return PaymentFailedException::gatewayUnreachable();
        }

        return PaymentFailedException::gatewayRefused($this->errorDetail($failure->body, $failure->status));
    }

    /**
     * The gateway's own words for a refusal, or the status code when it has none.
     *
     * @param  array<string, mixed>  $body
     */
    private function errorDetail(array $body, ?int $httpStatus = null): string
    {
        foreach (['validation_messages', 'error_messages'] as $key) {
            $messages = $body[$key] ?? null;

            if (is_array($messages) && $messages !== []) {
                return implode(' ', array_map(static fn (mixed $message): string => (string) $message, $messages));
            }
        }

        $message = $this->stringFrom($body, 'status_message');

        if ($message !== null) {
            return str_ends_with($message, '.') ? $message : "{$message}.";
        }

        return $httpStatus === null ? 'balasan tidak dapat dibaca.' : "HTTP {$httpStatus}.";
    }

    /**
     * The figure Midtrans says it collected, in whole rupiah.
     *
     * `gross_amount` arrives as a decimal string (`"358000.00"`), which is why
     * it is parsed rather than cast: `(int) "358000.00"` happens to be right
     * and `(int) "3.58e5"` would not be, and the comparison this feeds
     * decides whether an order is marked paid.
     *
     * @param  array<string, mixed>  $payload
     */
    private function amountFrom(array $payload): ?int
    {
        $gross = $this->stringFrom($payload, 'gross_amount');

        if ($gross === null || ! is_numeric($gross)) {
            return null;
        }

        return (int) round((float) $gross);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringFrom(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }
}
