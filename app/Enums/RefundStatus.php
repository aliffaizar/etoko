<?php

namespace App\Enums;

/**
 * Where one refund stands (P2-E4-T1, T4).
 *
 * Three states, mirroring `RefundResult`: `pending` is money the shop has
 * committed to returning but has not yet proved went back — a gateway
 * reversal in flight, or a transfer a staff member still has to make —
 * `processed` is money that has gone, and `failed` is a refund that did not
 * happen and whose amount therefore counts for nothing.
 *
 * **Only `processed` rows count towards how much of an order has been
 * refunded.** `Order::refundedAmount()` sums those alone, so a pending
 * reversal never makes an order read as paid back before it is, and a failed
 * one never permanently eats into what may still be returned.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Diproses',
            self::Processed => 'Sudah Dikembalikan',
            self::Failed => 'Gagal',
        };
    }

    /**
     * Whether the money has actually gone back.
     */
    public function isSettled(): bool
    {
        return $this === self::Processed;
    }

    /**
     * Whether this refund is still waiting on somebody or something.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
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
