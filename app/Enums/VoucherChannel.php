<?php

namespace App\Enums;

/**
 * Where a voucher may be redeemed (P2-E5-T1).
 *
 * Stored now so a voucher made today means the same thing once the POS
 * arrives in Phase 3; until then every order is `online`, and a `pos`-only
 * voucher is refused at the storefront with a sentence.
 */
enum VoucherChannel: string
{
    case All = 'all';
    case Online = 'online';
    case Pos = 'pos';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Online & kasir',
            self::Online => 'Hanya online',
            self::Pos => 'Hanya kasir (POS)',
        };
    }

    public function allows(OrderChannel $channel): bool
    {
        return match ($this) {
            self::All => true,
            self::Online => $channel === OrderChannel::Online,
            self::Pos => $channel === OrderChannel::Pos,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $channel): array => ['value' => $channel->value, 'label' => $channel->label()],
            self::cases(),
        );
    }
}
