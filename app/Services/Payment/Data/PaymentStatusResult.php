<?php

namespace App\Services\Payment\Data;

use App\Enums\PaymentTransactionStatus;

/**
 * What a provider said became of a payment (P2-E1-T6, ADR-004, ADR-053).
 *
 * A driver's whole job here is to go from an answer nobody else should have to
 * understand — a vocabulary of `capture`, `settlement`, `deny`, `cancel`,
 * `expire` — to three plain facts: which payment it is about, what state it is
 * now in, and what the gateway calls it. Everything after that is
 * `ApplyPaymentStatus`'s, and is the same for every gateway.
 *
 * `reference` is **our** id, the one the driver was told to key its transaction
 * on.
 *
 * `amount` is what the gateway says it collected, and it is carried here so
 * `ApplyPaymentStatus` can refuse a settlement for the wrong figure: an answer
 * about a part payment must not mark an order paid in full.
 *
 * `rejected()` is an answer that was understood and cannot be applied — the
 * wrong amount, or an order that has since moved on. It carries the reason,
 * which is logged.
 */
final class PaymentStatusResult
{
    /**
     * @param  array<string, mixed>  $payload  The payload as received, for the record.
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $reference = null,
        public readonly ?PaymentTransactionStatus $status = null,
        public readonly ?string $externalId = null,
        public readonly ?int $amount = null,
        public readonly ?string $reason = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function settled(
        string $reference,
        PaymentTransactionStatus $status,
        ?string $externalId = null,
        ?int $amount = null,
        array $payload = [],
    ): self {
        return new self(
            accepted: true,
            reference: $reference,
            status: $status,
            externalId: $externalId,
            amount: $amount,
            payload: $payload,
        );
    }

    /**
     * A payload that cannot be acted on, and why.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function rejected(string $reason, array $payload = []): self
    {
        return new self(accepted: false, reason: $reason, payload: $payload);
    }

    /**
     * A payload that is genuine but says nothing worth acting on — a
     * `transaction_status` the gateway uses for its own bookkeeping, such as
     * Midtrans' `authorize` on a card it has not captured yet.
     *
     * Accepted, so the gateway stops retrying, and recorded, so the payload is
     * not lost; but the payment is left exactly as it is.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function ignored(string $reference, string $reason, array $payload = []): self
    {
        return new self(accepted: true, reference: $reference, reason: $reason, payload: $payload);
    }

    /**
     * Whether this result asks for the payment's state to change.
     */
    public function changesState(): bool
    {
        return $this->accepted && $this->status instanceof PaymentTransactionStatus;
    }
}
