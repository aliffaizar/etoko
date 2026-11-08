<?php

namespace App\Services\Payment\Drivers;

use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Services\Payment\Data\PaymentCallbackResult;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\PaymentGateway;

/**
 * Payments a person settles (P2-E1-T2, T3, T7).
 *
 * The driver for everything the shop does itself: a transfer into its own bank
 * account, cash a courier collects, cash handed over at a till. There is no
 * third party, so there is nothing to call — which is the whole content of
 * this class and the reason it exists rather than being special-cased inside
 * the actions.
 *
 * - **`charge()`** returns an instruction with nothing in it. What the customer
 *   reads comes from `payment_methods.instructions` and `config.bank`, which
 *   are content the shop edits, and from the deadline `InitiatePayment`
 *   derives from the order's stock hold (ADR-041). A driver that invented the
 *   words would put them beyond the reach of whoever has to change the account
 *   number.
 * - **`handleCallback()`** rejects everything. Nobody calls a shop's webhook
 *   to say a transfer landed; a payload claiming to be a manual settlement is
 *   either a mistake or an attempt to mark an order paid without paying, and
 *   both deserve the same flat refusal. Manual money is confirmed by a staff
 *   member through `payments.verify` (`P2-E1-T4`), which records who said so.
 * - **`refund()`** says a person must move the money. The shop's own bank
 *   account cannot be instructed by this application, so claiming a refund
 *   succeeded would be the one lie a payment driver must never tell.
 */
final class ManualGateway implements PaymentGateway
{
    public function charge(Payment $payment): PaymentInstruction
    {
        return PaymentInstruction::none();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentCallbackResult
    {
        return PaymentCallbackResult::rejected(
            'Metode pembayaran manual tidak menerima callback; pembayaran manual diverifikasi staf.',
            $payload,
        );
    }

    public function refund(Payment $payment, int $amount): RefundResult
    {
        if ($amount > $payment->amount) {
            throw PaymentFailedException::unsupported(
                'Nominal pengembalian tidak boleh melebihi nominal pembayaran.',
            );
        }

        return RefundResult::manual(
            $amount,
            "Pembayaran {$payment->method_name} dikembalikan secara manual: transfer balik ke rekening pelanggan, "
                .'lalu catat buktinya pada dokumen refund.',
        );
    }
}
