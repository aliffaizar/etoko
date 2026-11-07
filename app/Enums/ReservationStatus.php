<?php

namespace App\Enums;

/**
 * The life of one stock hold (P1-E7-T1, BR-6).
 *
 * A hold starts `Active` and leaves in one of two ways, never back:
 *
 * - `Committed` — the goods left the shelf. `CommitReservation` lowered
 *   on-hand and the hold together, so the units are gone rather than held.
 * - `Released` — the hold was given back. The order was cancelled, or
 *   `ExpireStockReservations` swept it; on-hand never moved.
 *
 * Both are final, which is what makes a double release impossible to write:
 * the actions only ever act on `Active` rows.
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Committed = 'committed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ditahan',
            self::Committed => 'Terpakai',
            self::Released => 'Dilepas',
        };
    }

    /**
     * Whether this hold still occupies `stocks.reserved_quantity`.
     */
    public function isHolding(): bool
    {
        return $this === self::Active;
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
