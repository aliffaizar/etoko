<?php

namespace App\Services\Payment\Data;

use App\Enums\PaymentTransactionStatus;

/**
 * What a gateway's callback turned out to mean (P2-E1-T6, ADR-004).
 *
 * A driver's whole job here is to go from a payload nobody else should have to
 * understand — a signature to check, a vocabulary of `capture`, `settlement`,
 * `deny`, `cancel`, `expire` — to three plain facts: which payment it is about,
 * what state it is now in, and what the gateway calls it. Everything after
 * that is `HandlePaymentCallback`'s, and is the same for every gateway.
 *
 * `reference` is **our** id, the one the driver was told to key its transaction
 * on. Looking the payment up by an exact match on it is what makes a webhook
 * idempotent rather than clever.
 *
 * `amount` is what the gateway says it collected, and it is carried here so
 * `HandlePaymentCallback` can refuse a settlement for the wrong figure. The
 * signature already proves the payload is ours; this catches the different
 * problem of a genuine notification about a part payment, which must not mark
 * an order paid in full.
 *
 * An unverifiable or unrecognised payload is not an exception: a gateway
 * retries anything that is not answered, so a failure has to be describable in
 * a response rather than thrown. `rejected()` carries the reason, which is
 * logged and answered with a 4xx so the gateway stops retrying something that
 * will never work.
 */
final class PaymentCallbackResult
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
