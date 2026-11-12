<?php

namespace App\Enums;

/**
 * Who carries out a payment (P2-E1-T2, ADR-004).
 *
 * The value of `payment_methods.driver` is what `PaymentManager` resolves to a
 * `PaymentGateway` implementation. A method's `type` says what the customer
 * sees; this says which code runs.
 *
 * `Xendit` is listed because `docs/03-data-model.md` names it as the second
 * gateway the schema must accommodate, and leaving it out of the enum would
 * make adding it a migration. It has no driver class yet, so
 * `PaymentManager::for()` refuses a method that names it rather than failing
 * somewhere further down.
 */
enum PaymentDriver: string
{
    case Manual = 'manual';
    case Midtrans = 'midtrans';
    case Xendit = 'xendit';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual (verifikasi staf)',
            self::Midtrans => 'Midtrans',
            self::Xendit => 'Xendit',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $driver): array => ['value' => $driver->value, 'label' => $driver->label()],
            self::cases(),
        );
    }
}
