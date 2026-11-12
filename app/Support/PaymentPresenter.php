<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\PaymentMethodEligibility;
use Illuminate\Support\Collection;

/**
 * How payments reach the screens (P2-E1-T3, T4).
 *
 * Four screens read a payment — the customer's order page, the panel's payment
 * queue, the panel's payment detail and the panel's order detail — and they
 * must agree about what a status is called and what a figure is. Keeping the
 * shapes here is what stops that drifting, the same reason `OrderPresenter`
 * exists.
 *
 * Two rules this follows throughout:
 *
 * - **The money and the method come from the snapshot** (ADR-007), so a
 *   receipt still says "Transfer Bank BCA" after the method is renamed. The
 *   live method is read only for the things that are *instructions* — the bank
 *   account to pay into, the words to follow — which are current by nature.
 * - **The customer's shape is not the panel's.** `forCustomer()` carries no
 *   internal reason, no verifier and no gateway payload; a rejected receipt
 *   says what the customer must do, not what the staff member wrote in their
 *   note. The panel's `detail()` carries everything.
 *
 * The shapes mirror the TypeScript types in `resources/js/types/payment.ts`.
 */
final class PaymentPresenter
{
    /**
     * One row of the panel's payment queue.
     *
     * The order number and the customer name are expected to be loaded by the
     * caller (`with('order:id,order_number,customer_name,status')`), so drawing
     * a page costs no query per row.
     *
     * @return array<string, mixed>
     */
    public static function row(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'method_name' => $payment->method_name,
            'method_type' => $payment->method_type->value,
            'method_type_label' => $payment->method_type->label(),
            'amount' => $payment->amount,
            'fee_amount' => $payment->fee_amount,
            'has_proof' => $payment->proof_path !== null,
            'proof_uploaded_at' => $payment->proof_uploaded_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'has_overrun' => $payment->hasOverrun(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'order' => $payment->relationLoaded('order')
                ? [
                    'id' => $payment->order->id,
                    'order_number' => $payment->order->order_number,
                    'customer_name' => $payment->order->customer_name,
                    'status' => $payment->order->status->value,
                    'status_label' => $payment->order->status->label(),
                    'payment_status' => $payment->order->payment_status->value,
                    'payment_status_label' => $payment->order->payment_status->label(),
                    'grand_total' => $payment->order->grand_total,
                ]
                : null,
            'branch' => $payment->relationLoaded('branch')
                ? ['id' => $payment->branch->id, 'name' => $payment->branch->name]
                : null,
        ];
    }

    /**
     * One payment, as the panel's detail screen reads it.
     *
     * @param  array<string, bool>  $can  Capability flags the caller worked out from the policy.
     * @return array<string, mixed>
     */
    public static function detail(Payment $payment, array $can = []): array
    {
        return [
            ...self::row($payment),
            'external_id' => $payment->external_id,
            'va_number' => $payment->va_number,
            'qr_string' => $payment->qr_string,
            'payment_url' => $payment->payment_url,
            'proof_url' => $payment->proofUrl(),
            'proof_note' => $payment->proof_note,
            'failure_reason' => $payment->failure_reason,
            'verified_at' => $payment->verified_at?->toIso8601String(),
            'verified_by' => $payment->relationLoaded('verifiedBy') ? $payment->verifiedBy?->name : null,
            'raw_payload' => $payment->raw_payload,
            'instructions' => $payment->relationLoaded('paymentMethod')
                ? $payment->paymentMethod->instructions
                : null,
            'bank_account' => $payment->relationLoaded('paymentMethod')
                ? $payment->paymentMethod->bankAccount()
                : null,
            'can' => $can,
        ];
    }

    /**
     * The attempt as the customer's own order page reads it (P2-E1-T3).
     *
     * Deliberately narrower than the panel's shape: no verifier, no internal
     * reason, no gateway payload. What it does carry is everything needed to
     * *act* — the account to transfer into, the words to follow, the number or
     * code or link the gateway issued, the deadline, and whether a receipt may
     * still be uploaded.
     *
     * `instructions` and `bank_account` are read from the **live** method on
     * purpose, unlike every other field here: they are not a record of what
     * happened, they are what the customer has to do now, and a bank account
     * that has since changed is one no transfer should be sent to.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Payment $payment): array
    {
        $method = $payment->relationLoaded('paymentMethod') ? $payment->paymentMethod : null;

        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'method_name' => $payment->method_name,
            'method_type' => $payment->method_type->value,
            'method_type_label' => $payment->method_type->label(),
            'amount' => $payment->amount,
            'fee_amount' => $payment->fee_amount,
            'instructions' => $method?->instructions,
            'bank_account' => $method?->bankAccount(),
            'va_number' => $payment->va_number,
            'qr_string' => $payment->qr_string,
            'qr_image_url' => $payment->qrImageUrl(),
            'payment_url' => $payment->payment_url,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'has_overrun' => $payment->hasOverrun(),
            'accepts_proof' => $payment->acceptsProof(),
            'has_proof' => $payment->proof_path !== null,
            'proof_url' => $payment->proofUrl(),
            'proof_note' => $payment->proof_note,
            'proof_uploaded_at' => $payment->proof_uploaded_at?->toIso8601String(),
            // Said plainly, because a customer whose receipt was turned down
            // needs to know what to do differently — and the only sentences
            // written into `failure_reason` are ones meant to be read.
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * A payment method as a choice on a screen.
     *
     * `ineligible_reason` is carried even for a method that cannot be used,
     * because "COD tidak tersedia untuk pesanan di atas Rp2.000.000" tells the
     * customer what to do and a missing option tells them nothing. The screen
     * shows it disabled with its reason rather than hiding it.
     *
     * @return array<string, mixed>
     */
    public static function method(PaymentMethod $method, ?string $ineligibleReason = null): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'type' => $method->type->value,
            'type_label' => $method->type->label(),
            'provider_code' => $method->provider_code,
            'instructions' => $method->instructions,
            'logo_url' => $method->logoUrl(),
            'bank_account' => $method->bankAccount(),
            'fee_flat' => $method->fee_flat,
            'fee_percent' => (float) $method->fee_percent,
            'expects_proof' => $method->type->expectsProofOfTransfer(),
            'collected_on_delivery' => $method->type->isCollectedOnDelivery(),
            'is_eligible' => $ineligibleReason === null,
            'ineligible_reason' => $ineligibleReason,
        ];
    }

    /**
     * Every method a screen may offer, each with the fee and the verdict for
     * this particular order.
     *
     * Ineligible methods are **kept** rather than filtered out, for the reason
     * above; `fee_amount` is the figure this order would actually be charged,
     * so the screen never has to do the arithmetic twice.
     *
     * @param  Collection<int, PaymentMethod>  $methods
     * @return array<int, array<string, mixed>>
     */
    public static function methodChoices(
        Collection $methods,
        PaymentContext $context,
        PaymentMethodEligibility $eligibility,
    ): array {
        return $methods
            ->map(function (PaymentMethod $method) use ($context, $eligibility): array {
                $fee = $method->feeFor($context->amount);

                return [
                    ...self::method($method, $eligibility->reasonAgainst($method, $context)),
                    'fee_amount' => $fee,
                    'charge_amount' => $context->amount + $fee,
                ];
            })
            ->values()
            ->all();
    }
}
