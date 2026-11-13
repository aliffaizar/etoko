<?php

namespace App\Enums;

/**
 * Where a stock transfer between two branches stands (P2-E3).
 *
 * ```
 * requested ──approve──▶ approved ──ship──▶ in_transit ──receive──▶ received
 *     │                     │                                └──▶ partially_received
 *     ├──reject─────────────┴──▶ rejected
 *     └──cancel─────────────┴──▶ cancelled
 * ```
 *
 * Nothing moves until **ship**: approving is a promise, not a movement, so a
 * request that is approved and then rejected or cancelled leaves no trace in
 * the ledger (ADR-055). Once shipped the goods are on the road, counted in
 * neither branch, and the only way forward is receiving them.
 *
 * There is no draft: a request is typed and sent in one go, and taking it
 * back is a cancellation that stays on the record.
 */
enum StockTransferStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InTransit = 'in_transit';
    case Received = 'received';
    case PartiallyReceived = 'partially_received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::InTransit => 'Dalam Perjalanan',
            self::Received => 'Diterima',
            self::PartiallyReceived => 'Diterima Sebagian',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Whether nothing further can happen to the transfer.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Received, self::PartiallyReceived, self::Cancelled], strict: true);
    }

    /**
     * Whether the transfer may still be stopped without any stock having moved.
     */
    public function isStoppable(): bool
    {
        return in_array($this, [self::Requested, self::Approved], strict: true);
    }

    /**
     * Whether the origin warehouse has already given the goods up.
     */
    public function hasShipped(): bool
    {
        return in_array($this, [self::InTransit, self::Received, self::PartiallyReceived], strict: true);
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
