<?php

namespace App\Services\Payment\Data;

/**
 * What came of asking for money back (P2-E1-T2, ADR-004).
 *
 * Three outcomes rather than two, because a refund is not always an answer
 * the moment it is asked for:
 *
 * - **`settled`** — the money has gone back, and the gateway says so.
 * - **`pending`** — the gateway accepted the request and will carry it out,
 *   which is what a bank transfer reversal looks like.
 * - **`manual`** — there is no gateway to ask. A transfer that arrived by hand
 *   goes back by hand, and the only honest thing the driver can do is say so,
 *   so a person is told to make the transfer instead of a screen claiming it
 *   happened.
 * - **`failed`** — the gateway refused, and the reason is the gateway's.
 *
 * The refund *flow* — who may ask, what it does to the order's lines and its
 * `payment_status` — is `P2-E4` (ADR-035). This type exists now because the
 * interface in ADR-004 names it, and a driver that cannot answer `refund()` is
 * a driver that will have to change when the flow arrives.
 */
final class RefundResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly int $amount,
        public readonly ?string $externalId = null,
        public readonly ?string $reason = null,
    ) {}

    public const OUTCOME_SETTLED = 'settled';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_MANUAL = 'manual';

    public const OUTCOME_FAILED = 'failed';

    public static function settled(int $amount, ?string $externalId = null): self
    {
        return new self(self::OUTCOME_SETTLED, $amount, $externalId);
    }

    public static function pending(int $amount, ?string $externalId = null): self
    {
        return new self(self::OUTCOME_PENDING, $amount, $externalId);
    }

    /**
     * No gateway to ask: somebody has to transfer the money back.
     */
    public static function manual(int $amount, string $reason): self
    {
        return new self(self::OUTCOME_MANUAL, $amount, reason: $reason);
    }

    public static function failed(int $amount, string $reason): self
    {
        return new self(self::OUTCOME_FAILED, $amount, reason: $reason);
    }

    /**
     * Whether the money is on its way back without anybody doing anything else.
     */
    public function isAccepted(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_SETTLED, self::OUTCOME_PENDING], strict: true);
    }

    /**
     * Whether a person still has to move the money.
     */
    public function needsHuman(): bool
    {
        return $this->outcome === self::OUTCOME_MANUAL;
    }
}
