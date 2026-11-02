<?php

namespace App\Enums;

enum StockMovementType: string
{
    case EntryIn = 'entry_in';
    case IssueOut = 'issue_out';
    case SaleOut = 'sale_out';
    case SaleReturnIn = 'sale_return_in';
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
