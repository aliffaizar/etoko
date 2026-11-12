<?php

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Scopes\BranchScope;
use App\Services\Payment\Data\PaymentStatusResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies what a provider said about a payment to the payment and its order
 * (P2-E1-T6, ADR-053).
 *
 * The one place a gateway's answer becomes a settled order, a failed attempt
 * or a recorded refund. It is reached only through `ReconcilePayment`, which
 * asked the provider itself, so the answer is authentic and `$payment` is the
 * attempt it names. Two things are still checked here, because they are about
 * the answer rather than about who sent it:
 *
 * 1. **The amount has to match.** A genuine answer about a part payment must
 *    not mark an order paid in full.
 * 2. **Idempotency is by state.** The payment's own row is the record: an
 *    answer whose outcome the attempt has already reached does nothing, and
 *    one arriving for an attempt that has finished differently is recorded and
 *    logged loudly rather than applied. The order page and two scheduled jobs
 *    all ask about the same attempt, so "already handled" is the common case.
 *
 * Every answer applied here is stored on `payments.raw_payload`, replacing
 * the previous one. The *history* is in the application log rather than the
 * database, which is a deliberate limit: it is enough to reconstruct a dispute
 * and not a growing JSON column nobody prunes.
 */
final class ApplyPaymentStatus
{
    public function __construct(
        private readonly VerifyPayment $verifyPayment,
        private readonly FailPayment $failPayment,
        private readonly ExpirePayment $expirePayment,
    ) {}

    /**
     * Apply what a provider said about `$payment`.
     *
     * The caller is responsible for the result being authentic and for
     * `$payment` being the attempt it names.
     */
    public function handle(Payment $payment, PaymentStatusResult $result): PaymentStatusResult
    {
        $payload = $result->payload;

        $this->recordPayload($payment, $result);

        if ($mismatch = $this->amountMismatch($payment, $result)) {
            Log::warning('Received a payment status answer whose amount did not match.', [
                'reference' => $payment->reference,
                'expected' => $payment->amount,
                'received' => $result->amount,
            ]);

            return PaymentStatusResult::rejected($mismatch, $payload);
        }

        if (! $result->changesState()) {
            Log::info('Recorded a payment status answer that changed nothing.', [
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
    private function recordPayload(Payment $payment, PaymentStatusResult $result): void
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
     * Why the figure the provider reports is not the figure we asked for, or null.
     *
     * An answer with no amount at all is not treated as a mismatch: some
     * gateway events carry none, and refusing those would leave real
     * settlements unprocessed. The signature has already proved the payload is
     * ours.
     */
    private function amountMismatch(Payment $payment, PaymentStatusResult $result): ?string
    {
        if ($result->amount === null || $result->amount === $payment->amount) {
            return null;
        }

        return "Nominal dari penyedia ({$result->amount}) tidak sama dengan nominal pembayaran ({$payment->amount}).";
    }

    /**
     * Take the payment where the provider says it now is.
     *
     * @param  array<string, mixed>  $payload
     */
    private function apply(Payment $payment, PaymentStatusResult $result, array $payload): PaymentStatusResult
    {
        $status = $result->status;

        if (! $status instanceof PaymentTransactionStatus) {
            return $result;
        }

        if ($payment->status === $status) {
            Log::info('Ignored a duplicate payment status answer.', [
                'reference' => $payment->reference,
                'status' => $status->value,
            ]);

            return PaymentStatusResult::ignored(
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
            Log::warning('Received a payment status answer for an attempt that had already finished.', [
                'reference' => $payment->reference,
                'current' => $payment->status->value,
                'answer' => $status->value,
            ]);

            if ($status === PaymentTransactionStatus::Refunded) {
                $this->recordGatewayRefund($payment);
            }

            return PaymentStatusResult::ignored(
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
            // The order moved on before the answer was applied — expired
            // and swept, most likely. The money is real, so this is a refund
            // conversation rather than a status change, and the loudest place
            // to say so is the log plus the payload now on the row.
            Log::error('Could not apply a payment status answer.', [
                'reference' => $payment->reference,
                'answer' => $status->value,
                'reason' => $refused->getMessage(),
            ]);

            return PaymentStatusResult::rejected($refused->getMessage(), $payload);
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
