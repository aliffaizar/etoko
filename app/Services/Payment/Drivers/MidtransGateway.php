<?php

namespace App\Services\Payment\Drivers;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Services\Payment\Data\PaymentCallbackResult;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Midtrans, behind the same interface as a bank transfer (P2-E1-T5, ADR-004).
 *
 * **Snap for every type**, rather than one Core API call per type. Snap issues
 * one transaction and returns a page the customer is sent to, and
 * `enabled_payments` narrows that page to the channels the chosen method is
 * about — so picking "QRIS" opens a QRIS page rather than a menu of nine
 * things. The alternative, Core API `/v2/charge` per type, means a different
 * request body and a different success shape for virtual accounts, QRIS and
 * each e-wallet, and three more places for the same bug to live. Snap is also
 * what Midtrans keeps current.
 *
 * Nothing here is written to the database: this is a driver (see
 * `PaymentGateway`). The `payment_url` and the deadline it comes back with are
 * persisted by `InitiatePayment`, inside its transaction.
 *
 * **`transaction_details.order_id` is the payment's `reference`, not the order
 * number.** Midtrans refuses a second transaction under an id it has seen, so
 * keying on the order number would make every retry of a failed attempt fail
 * too; and the callback quotes the id back, so a per-attempt reference is what
 * lets a webhook find the row it is about by an exact match.
 *
 * **Signature verification is not optional.** The webhook is an
 * unauthenticated endpoint, and the signature is the only thing between it and
 * anyone marking their own order paid: `sha512(order_id + status_code +
 * gross_amount + server_key)`. A payload whose signature does not match is
 * rejected, logged, and never looked at again.
 *
 * Testing: every call goes through the `Http` facade, so the integration tests
 * drive it with `Http::fake()` and no sandbox credentials. That is deliberate —
 * the project has no Midtrans keys yet, and a driver that can only be tested
 * against a live sandbox is a driver that is not tested.
 */
final class MidtransGateway implements PaymentGateway
{
    /**
     * The `transaction_status` values Midtrans sends, and what each one means
     * to us.
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
     * Ask Snap for a transaction and return where to send the customer.
     *
     * @throws PaymentFailedException
     */
    public function charge(Payment $payment): PaymentInstruction
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->post(
                $this->snapUrl('/snap/v1/transactions'),
                $this->snapBody($payment),
            ),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw PaymentFailedException::gatewayRefused($this->errorDetail($body, $response->status()));
        }

        $redirectUrl = $body['redirect_url'] ?? null;

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            throw PaymentFailedException::gatewayRefused('balasan Snap tidak memuat tautan pembayaran.');
        }

        return new PaymentInstruction(
            paymentUrl: $redirectUrl,
            expiresAt: $this->chargeDeadline($payment),
            rawPayload: $body,
        );
    }

    /**
     * Turn a notification into a fact, having first proved it is genuine.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentCallbackResult
    {
        $reference = $this->stringFrom($payload, 'order_id');

        if ($reference === null) {
            return PaymentCallbackResult::rejected('Payload tidak memuat order_id.', $payload);
        }

        if (! $this->signatureMatches($payload)) {
            Log::warning('Rejected a Midtrans callback whose signature did not match.', [
                'reference' => $reference,
                'transaction_status' => $this->stringFrom($payload, 'transaction_status'),
            ]);

            return PaymentCallbackResult::rejected('Signature callback tidak sah.', $payload);
        }

        $transactionStatus = $this->stringFrom($payload, 'transaction_status');

        if ($transactionStatus === null) {
            return PaymentCallbackResult::rejected('Payload tidak memuat transaction_status.', $payload);
        }

        $status = $this->statusFor($transactionStatus, $this->stringFrom($payload, 'fraud_status'));

        if (! $status instanceof PaymentTransactionStatus) {
            return PaymentCallbackResult::ignored(
                $reference,
                "Status \"{$transactionStatus}\" belum mengubah apa pun pada pembayaran.",
                $payload,
            );
        }

        return PaymentCallbackResult::settled(
            reference: $reference,
            status: $status,
            externalId: $this->stringFrom($payload, 'transaction_id'),
            amount: $this->amountFrom($payload),
            payload: $payload,
        );
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
            $response = $this->send(
                fn (PendingRequest $request): Response => $request->post(
                    $this->apiUrl("/v2/{$payment->reference}/refund"),
                    [
                        'refund_key' => "RFD-{$payment->getKey()}-{$amount}",
                        'amount' => $amount,
                        'reason' => "Refund pesanan {$payment->order->order_number}",
                    ],
                ),
            );
        } catch (PaymentFailedException $unreachable) {
            return RefundResult::failed($amount, $unreachable->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $statusCode = $this->stringFrom($body, 'status_code');

        // Midtrans answers a refund with its own status code rather than with
        // the HTTP status, so a 200 carrying `412` is a refusal.
        if (! $response->successful() || ! in_array($statusCode, ['200', '201', '202'], strict: true)) {
            return RefundResult::failed($amount, $this->errorDetail($body, $response->status()));
        }

        $externalId = $this->stringFrom($body, 'refund_key') ?? $this->stringFrom($body, 'transaction_id');

        return $statusCode === '200'
            ? RefundResult::settled($amount, $externalId)
            : RefundResult::pending($amount, $externalId);
    }

    /**
     * The Snap request body.
     *
     * `item_details` is sent because without it Midtrans shows the customer a
     * single unnamed line on its own page, and `gross_amount` must equal the
     * sum of the lines or Snap refuses the transaction — so the method's fee
     * is sent as a line of its own rather than folded into a price.
     *
     * @return array<string, mixed>
     */
    private function snapBody(Payment $payment): array
    {
        $order = $payment->order;
        $enabled = $payment->paymentMethod->midtransEnabledPayments();

        $body = [
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

        if ($enabled !== []) {
            $body['enabled_payments'] = $enabled;
        }

        $minutes = $this->minutesUntil($this->chargeDeadline($payment));

        if ($minutes !== null) {
            $body['expiry'] = ['unit' => 'minute', 'duration' => $minutes];
        }

        return $body;
    }

    /**
     * The lines Snap shows, adding up to exactly `gross_amount`.
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
     * The deadline to ask Snap for: the payment's own, which `InitiatePayment`
     * already derived from the order's stock hold (ADR-041).
     *
     * Asking Snap for a longer window than the hold would have Midtrans accept
     * money for goods that had gone back on sale.
     */
    private function chargeDeadline(Payment $payment): ?CarbonInterface
    {
        return $payment->expires_at;
    }

    /**
     * Whole minutes from now until the deadline, or null when there is none.
     *
     * At least one: Snap rejects a zero duration, and a deadline that has
     * already passed is a race `InitiatePayment` has lost rather than something
     * to encode as a negative.
     */
    private function minutesUntil(?CarbonInterface $deadline): ?int
    {
        if ($deadline === null) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInMinutes($deadline, absolute: false)));
    }

    /**
     * Whether the payload was signed with our server key.
     *
     * The three fields are concatenated in the order Midtrans documents, and
     * `hash_equals` is used rather than `===` so the comparison does not leak
     * how much of a forged signature was correct.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signatureMatches(array $payload): bool
    {
        $signature = $this->stringFrom($payload, 'signature_key');
        $serverKey = $this->serverKey();

        if ($serverKey === null) {
            // Without a key there is nothing to verify against. Refuse, unless
            // a developer has explicitly asked to replay captured payloads on
            // a machine that is not production.
            return (bool) config('services.midtrans.tolerate_unsigned_webhook') && ! app()->isProduction();
        }

        if ($signature === null) {
            return false;
        }

        $expected = hash('sha512', sprintf(
            '%s%s%s%s',
            $this->stringFrom($payload, 'order_id') ?? '',
            $this->stringFrom($payload, 'status_code') ?? '',
            $this->stringFrom($payload, 'gross_amount') ?? '',
            $serverKey,
        ));

        return hash_equals($expected, $signature);
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
     * Send a request, turning a dead connection into a sentence.
     *
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws PaymentFailedException
     */
    private function send(callable $send): Response
    {
        $serverKey = $this->serverKey();

        if ($serverKey === null) {
            throw PaymentFailedException::unsupported(
                'Pembayaran Midtrans belum dikonfigurasi (MIDTRANS_SERVER_KEY kosong). Pilih metode pembayaran lain.',
            );
        }

        $request = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.midtrans.timeout', 15));

        try {
            return $send($request);
        } catch (ConnectionException $unreachable) {
            Log::warning('Midtrans could not be reached.', ['message' => $unreachable->getMessage()]);

            throw PaymentFailedException::gatewayUnreachable();
        }
    }

    /**
     * The gateway's own words for a refusal, or the status code when it has none.
     *
     * @param  array<string, mixed>  $body
     */
    private function errorDetail(array $body, int $httpStatus): string
    {
        $messages = $body['error_messages'] ?? null;

        if (is_array($messages) && $messages !== []) {
            return implode(' ', array_map(static fn (mixed $message): string => (string) $message, $messages));
        }

        $message = $this->stringFrom($body, 'status_message');

        return $message ?? "HTTP {$httpStatus}.";
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

    private function serverKey(): ?string
    {
        $key = config('services.midtrans.server_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function snapUrl(string $path): string
    {
        return rtrim((string) config('services.midtrans.snap_base_url'), '/').$path;
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('services.midtrans.api_base_url'), '/').$path;
    }
}
