<?php

namespace App\Enums;

/**
 * Where one payment attempt stands (P2-E1-T1).
 *
 * Deliberately **not** `PaymentStatus`, which is the order's standing with
 * respect to money (`orders.payment_status`). The two answer different
 * questions and must be allowed to disagree: a COD order is `pending` on both
 * counts at first, but a failed card attempt leaves the *attempt* `failed`
 * while the *order* is still waiting to be paid and can be tried again.
 *
 * `Success`, `Failed`, `Expired` and `Refunded` are final. A payment is never
 * reopened — a second try is a second row, which is what keeps "we asked for
 * this money twice" visible instead of overwritten.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Pembayaran',
            self::Success => 'Berhasil',
            self::Failed => 'Gagal',
            self::Expired => 'Kedaluwarsa',
            self::Refunded => 'Dikembalikan',
        };
    }

    /**
     * Whether this attempt is still awaiting money.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether this attempt has reached a state it will never leave.
     */
    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * The order-level standing this attempt implies.
     *
     * One map rather than a `match` in each action, so "a failed payment
     * leaves the order failed" cannot be decided two ways. `Success` is absent
     * on purpose: reaching it moves the **order** through
     * `TransitionOrderStatus`, which is the only thing allowed to write
     * `orders.status`, and it sets `payment_status` itself.
     */
    public function orderPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Pending => PaymentStatus::Pending,
            self::Success => PaymentStatus::Paid,
            self::Failed => PaymentStatus::Failed,
            self::Expired => PaymentStatus::Expired,
            self::Refunded => PaymentStatus::Refunded,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
