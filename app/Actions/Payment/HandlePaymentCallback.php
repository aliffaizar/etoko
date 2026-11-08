<?php

namespace App\Actions\Payment;

use App\Enums\PaymentDriver;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Scopes\BranchScope;
use App\Services\Payment\Data\PaymentCallbackResult;
use App\Services\Payment\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a gateway's notification into a settled order (P2-E1-T6).
 *
 * The endpoint behind this is unauthenticated and reachable by anybody, so
 * every step here is written as though the payload were hostile:
 *
 * 1. **The driver verifies the signature** before anything else, and a payload
 *    that fails is rejected without a database read. That check is the only
 *    thing between this route and somebody marking their own order paid.
 * 2. **The payment is found by our own `reference`**, never by an id from the
 *    URL or the body's own idea of which order it is.
 * 3. **The driver has to match the payment's method.** A Midtrans
 *    notification must not settle a bank transfer, even with a valid Midtrans
 *    signature: the two are different money, and the cross-driver case is
 *    exactly what an attacker with one valid sandbox signature would try.
 * 4. **The amount has to match.** A genuine notification about a part payment
 *    must not mark an order paid in full. The signature proves the payload is
 *    ours; this proves it is about the whole bill.
 *
 * **Idempotency is by state, not by a log of event ids.** The payment's own
 * row is the record: a callback whose outcome the attempt has already reached
 * is accepted and does nothing, and one arriving for an attempt that has
 * finished differently is accepted, recorded and logged loudly rather than
 * applied. Gateways retry until they are answered with a 2xx, so "already
 * handled" has to be a success rather than an error; answering 500 to a
 * duplicate would have Midtrans redelivering it for hours.
 *
 * Every payload reaching a known payment is stored on `payments.raw_payload`,
 * replacing the previous one. The *history* of notifications is in the
 * application log rather than the database, which is a deliberate limit: it is
 * enough to reconstruct a dispute and not a growing JSON column nobody prunes.
 */
final class HandlePaymentCallback
{
    public function __construct(
        private readonly PaymentManager $gateways,
        private readonly VerifyPayment $verifyPayment,
        private readonly FailPayment $failPayment,
        private readonly ExpirePayment $expirePayment,
    ) {}

    /**
     * Act on a notification from `$driver`.
     *
     * Returns the result the controller turns into a status code. Nothing is
     * thrown for a bad payload: a gateway cannot read an exception, and an
     * unanswered callback is a callback that comes back.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(PaymentDriver $driver, array $payload): PaymentCallbackResult
    {
        try {
            $result = $this->gateways->driver($driver)->handleCallback($payload);
        } catch (PaymentFailedException $unsupported) {
            return PaymentCallbackResult::rejected($unsupported->getMessage(), $payload);
        }

        if (! $result->accepted || $result->reference === null) {
            Log::warning('Rejected a payment callback.', [
                'driver' => $driver->value,
                'reason' => $result->reason,
            ]);

            return $result;
        }

        $payment = Payment::withoutGlobalScope(BranchScope::class)
            ->where('reference', $result->reference)
            ->with(['order', 'paymentMethod'])
            ->first();

        if (! $payment instanceof Payment) {
            Log::warning('Received a payment callback for an unknown reference.', [
                'driver' => $driver->value,
                'reference' => $result->reference,
            ]);

            return PaymentCallbackResult::rejected(
                "Tidak ada pembayaran dengan referensi {$result->reference}.",
                $payload,
            );
        }

        if ($payment->paymentMethod->driver !== $driver) {
            Log::warning('Received a payment callback from the wrong driver.', [
                'driver' => $driver->value,
                'reference' => $result->reference,
                'payment_driver' => $payment->paymentMethod->driver->value,
            ]);

            return PaymentCallbackResult::rejected(
                'Referensi pembayaran ini bukan milik penyedia pembayaran tersebut.',
                $payload,
            );
        }

        $this->recordPayload($payment, $result);

        if ($mismatch = $this->amountMismatch($payment, $result)) {
            Log::warning('Received a payment callback whose amount did not match.', [
                'reference' => $payment->reference,
                'expected' => $payment->amount,
                'received' => $result->amount,
            ]);

            return PaymentCallbackResult::rejected($mismatch, $payload);
        }

        if (! $result->changesState()) {
            Log::info('Recorded a payment callback that changed nothing.', [
                'reference' => $payment->reference,
                'reason' => $result->reason,
            ]);

            return $result;
        }

        return $this->apply($payment, $result, $payload);
    }

    /**
     * Keep the payload and the gateway's own id, whatever the outcome.
     *
     * Written outside the actions that follow so that a rejected amount or an
     * unexpected status still leaves the evidence behind — the payload is most
     * valuable precisely when something did not go to plan.
     */
    private function recordPayload(Payment $payment, PaymentCallbackResult $result): void
    {
        $payment->update(array_filter(
            [
                'raw_payload' => $result->payload,
                'external_id' => $result->externalId ?? $payment->external_id,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * Why the figure in the callback is not the figure we asked for, or null.
     *
     * A callback with no amount at all is not treated as a mismatch: some
     * gateway events carry none, and refusing those would leave real
     * settlements unprocessed. The signature has already proved the payload is
     * ours.
     */
    private function amountMismatch(Payment $payment, PaymentCallbackResult $result): ?string
    {
        if ($result->amount === null || $result->amount === $payment->amount) {
            return null;
        }

        return "Nominal callback ({$result->amount}) tidak sama dengan nominal pembayaran ({$payment->amount}).";
    }

    /**
     * Take the payment where the callback says it now is.
     *
     * @param  array<string, mixed>  $payload
     */
    private function apply(Payment $payment, PaymentCallbackResult $result, array $payload): PaymentCallbackResult
    {
        $status = $result->status;

        if (! $status instanceof PaymentTransactionStatus) {
            return $result;
        }

        if ($payment->status === $status) {
            Log::info('Ignored a duplicate payment callback.', [
                'reference' => $payment->reference,
                'status' => $status->value,
            ]);

            return PaymentCallbackResult::ignored(
                $payment->reference,
                'Pembayaran sudah berada pada status tersebut.',
                $payload,
            );
        }

        if ($payment->status->isFinal()) {
            // A gateway changing its mind after the fact — a refund notice for
            // a payment we already recorded, or a `deny` after a `settlement`.
            // Loud, because it means the books and the gateway disagree, and
            // accepted, because arguing with a retry loop achieves nothing.
            Log::warning('Received a payment callback for an attempt that had already finished.', [
                'reference' => $payment->reference,
                'current' => $payment->status->value,
                'callback' => $status->value,
            ]);

            if ($status === PaymentTransactionStatus::Refunded) {
                $this->recordGatewayRefund($payment);
            }

            return PaymentCallbackResult::ignored(
                $payment->reference,
                "Pembayaran sudah berstatus \"{$payment->status->label()}\".",
                $payload,
            );
        }

        try {
            match ($status) {
                PaymentTransactionStatus::Success => $this->verifyPayment->handle(
                    $payment,
                    note: "Pembayaran {$payment->method_name} dikonfirmasi otomatis oleh penyedia pembayaran.",
                ),
                PaymentTransactionStatus::Failed => $this->failPayment->handle(
                    $payment,
                    'Penyedia pembayaran melaporkan transaksi gagal atau dibatalkan.',
                ),
                PaymentTransactionStatus::Expired => $this->expirePayment->handle($payment),
                PaymentTransactionStatus::Refunded => $this->recordGatewayRefund($payment),
                PaymentTransactionStatus::Pending => null,
            };
        } catch (PaymentFailedException $refused) {
            // The order moved on while the callback was in flight — expired
            // and swept, most likely. The money is real, so this is a refund
            // conversation rather than a status change, and the loudest place
            // to say so is the log plus the payload now on the row.
            Log::error('Could not apply a payment callback.', [
                'reference' => $payment->reference,
                'callback' => $status->value,
                'reason' => $refused->getMessage(),
            ]);

            return PaymentCallbackResult::rejected($refused->getMessage(), $payload);
        }

        return $result;
    }

    /**
     * Record that the gateway says it sent the money back.
     *
     * The refund *flow* — the document, the restocking, the order's lines — is
     * `P2-E4` (ADR-035). All this does is stop the books claiming money the
     * gateway no longer holds, which is the part that cannot wait for an epic.
     */
    private function recordGatewayRefund(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::withoutGlobalScope(BranchScope::class)
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update(['status' => PaymentTransactionStatus::Refunded]);

            $order = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($locked->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order->update(['payment_status' => PaymentStatus::Refunded]);
        });

        Log::warning('Recorded a refund reported by the payment provider.', [
            'payment_id' => $payment->getKey(),
            'order_id' => $payment->order_id,
            'reference' => $payment->reference,
        ]);
    }
}
