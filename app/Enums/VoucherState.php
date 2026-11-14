<?php

namespace App\Enums;

/**
 * Where a voucher stands right now, as the admin listing reads it (P2-E5-T2).
 *
 * Worked out from `is_active`, the period and the quota — never stored, so it
 * cannot go stale when the clock passes `ends_at`. `Voucher::state()` is the
 * one place that decides it, and `Voucher::scopeInState()` the same rule as a
 * query for the listing's filter.
 */
enum VoucherState: string
{
    case Running = 'running';
    case Scheduled = 'scheduled';
    case Ended = 'ended';
    case Exhausted = 'exhausted';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Berjalan',
            self::Scheduled => 'Terjadwal',
            self::Ended => 'Berakhir',
            self::Exhausted => 'Kuota Habis',
            self::Inactive => 'Nonaktif',
        };
    }

    /**
     * The `StatusBadge` tone the listing paints this state with.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Running => 'success',
            self::Scheduled => 'info',
            self::Ended, self::Exhausted => 'neutral',
            self::Inactive => 'danger',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}
