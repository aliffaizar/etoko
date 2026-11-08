<?php

namespace App\Services\Payment\Data;

use Carbon\CarbonInterface;

/**
 * What a driver gives back when asked to collect money (P2-E1-T2, ADR-004).
 *
 * Everything a customer needs in order to pay, and nothing about *how* it was
 * obtained — which is what lets a bank account typed into a config file and a
 * virtual account issued by Midtrans arrive at the same screen.
 *
 * Every field is optional because methods differ in kind rather than in
 * degree: a virtual account has a number, a QRIS has a code to render, an
 * e-wallet has a URL to send the customer to, a manual transfer has only words,
 * and a COD has nothing at all. The screen shows whichever of them is there.
 *
 * `expiresAt` is a driver's *own* deadline, which may be shorter than the
 * order's stock hold — a Midtrans VA that closes in five minutes closes in five
 * minutes. `InitiatePayment` takes the earlier of the two (ADR-041), so the
 * payment can never outlive the goods it is for.
 */
final class PaymentInstruction
{
    /**
     * @param  array<string, mixed>|null  $rawPayload  The gateway's own answer, kept for disputes.
     */
    public function __construct(
        public readonly ?string $externalId = null,
        public readonly ?string $vaNumber = null,
        public readonly ?string $qrString = null,
        public readonly ?string $paymentUrl = null,
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly ?array $rawPayload = null,
    ) {}

    /**
     * An instruction with nothing to show: the method is settled by people.
     *
     * A manual transfer and a COD both return this. The words the customer
     * reads come from `payment_methods.instructions`, which is content the shop
     * edits, not something a driver invents.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * The columns this instruction fills in on the payment row.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return array_filter(
            [
                'external_id' => $this->externalId,
                'va_number' => $this->vaNumber,
                'qr_string' => $this->qrString,
                'payment_url' => $this->paymentUrl,
                'raw_payload' => $this->rawPayload,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * Whether there is anything for the customer to act on besides words.
     */
    public function hasActionable(): bool
    {
        return $this->vaNumber !== null || $this->qrString !== null || $this->paymentUrl !== null;
    }

    /**
     * The deadline to put on the payment: the earlier of the driver's own and
     * the one the order's stock hold imposes.
     */
    public function deadlineAgainst(?CarbonInterface $orderDeadline): ?CarbonInterface
    {
        if ($this->expiresAt === null) {
            return $orderDeadline;
        }

        if ($orderDeadline === null) {
            return $this->expiresAt;
        }

        return $this->expiresAt->lessThan($orderDeadline) ? $this->expiresAt : $orderDeadline;
    }
}
