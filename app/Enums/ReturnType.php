<?php

namespace App\Enums;

/**
 * What the customer is asking for when they send goods back (P2-E4-T1).
 *
 * Two kinds, because they end differently and the shop has to know which it
 * is promising before the parcel arrives: a `refund` ends in money going back
 * (`refunds`), an `exchange` ends in a replacement going out.
 *
 * **Only `refund` is carried out end to end in this epic.** An exchange is
 * accepted, received and restocked by the same screens — the goods coming
 * back is the same event either way — but the replacement order itself is not
 * raised automatically, because that is a new order against a possibly
 * different price and a possibly short shelf. `ProcessRefund` refuses an
 * exchange with a sentence rather than quietly paying money out for one.
 */
enum ReturnType: string
{
    case Refund = 'refund';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Pengembalian Dana',
            self::Exchange => 'Tukar Barang',
        };
    }

    /**
     * Whether a return of this kind ends in money going back.
     */
    public function endsInRefund(): bool
    {
        return $this === self::Refund;
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
