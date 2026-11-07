<?php

namespace App\Enums;

enum StockMovementType: string
{
    case EntryIn = 'entry_in';
    case IssueOut = 'issue_out';
    case SaleOut = 'sale_out';
    case SaleReturnIn = 'sale_return_in';
    case SaleReservation = 'sale_reservation';
    case SaleReservationRelease = 'sale_reservation_release';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case OpnameAdjustment = 'opname_adjustment';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::EntryIn => 'Barang Masuk',
            self::IssueOut => 'Barang Keluar',
            self::SaleOut => 'Penjualan',
            self::SaleReturnIn => 'Retur Penjualan',
            self::SaleReservation => 'Ditahan Checkout',
            self::SaleReservationRelease => 'Penahanan Dilepas',
            self::TransferOut => 'Transfer Keluar',
            self::TransferIn => 'Transfer Masuk',
            self::OpnameAdjustment => 'Penyesuaian Opname',
            self::ManualAdjustment => 'Penyesuaian Manual',
        };
    }

    /**
     * Whether this movement type always increases stock.
     *
     * Adjustment types may move stock in either direction, so they return false
     * here and rely on the signed quantity recorded on the movement.
     */
    public function isInbound(): bool
    {
        return in_array($this, [self::EntryIn, self::SaleReturnIn, self::TransferIn], strict: true);
    }

    public function isOutbound(): bool
    {
        return in_array($this, [self::IssueOut, self::SaleOut, self::TransferOut], strict: true);
    }

    /**
     * Whether this type only moves `reserved_quantity`, leaving on-hand alone.
     *
     * Checkout taking a hold and a hold being given back are both real events
     * — they change what may be sold (BR-6) — but nothing leaves the shelf, so
     * their ledger rows carry `quantity_change = 0` and record the same figure
     * before and after. The stock movement screen says so rather than showing
     * a blank change (P1-E7-T2).
     */
    public function isReservationOnly(): bool
    {
        return in_array($this, [self::SaleReservation, self::SaleReservationRelease], strict: true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
