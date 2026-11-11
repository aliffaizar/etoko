<?php

namespace App\Enums;

/**
 * Why the customer is sending goods back (P2-E4-T1, T2).
 *
 * A fixed list rather than free text, because the whole value of recording a
 * reason is being able to count it: "how many parcels arrived damaged last
 * month, and from which branch?" is a question a text column cannot answer.
 * The customer's own words still go in `returns.description`, which is where
 * the detail belongs.
 *
 * `faultOfShop()` is the distinction that matters commercially: goods that
 * arrived broken or wrong are the shop's mistake and the delivery fee goes
 * back with the goods, while a change of mind is not — that is the rule
 * `Refund::suggestedAmountFor()` applies so a staff member is not left doing
 * the arithmetic from memory.
 */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case MissingItem = 'missing_item';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Barang rusak / cacat',
            self::WrongItem => 'Barang tidak sesuai pesanan',
            self::NotAsDescribed => 'Barang tidak sesuai deskripsi',
            self::MissingItem => 'Barang kurang / tidak lengkap',
            self::ChangedMind => 'Berubah pikiran',
            self::Other => 'Alasan lain',
        };
    }

    /**
     * Whether this reason is the shop's own mistake.
     *
     * Decides whether the delivery the customer paid for goes back with the
     * goods: a parcel that arrived broken was never delivered properly, while
     * a change of mind was.
     */
    public function faultOfShop(): bool
    {
        return in_array(
            $this,
            [self::Damaged, self::WrongItem, self::NotAsDescribed, self::MissingItem],
            strict: true,
        );
    }

    /**
     * Whether the customer must say more than the label.
     *
     * "Alasan lain" on its own tells the branch nothing it can act on, so the
     * description stops being optional for it.
     */
    public function requiresDescription(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
