<?php

namespace App\Enums;

/**
 * Which code carries out a courier's work (P2-E2-T1, ADR-004).
 *
 * The sibling of `PaymentDriver`, and the same separation of concerns: the
 * courier row says *who* ships ("JNE"), this says *who we talk to* about it.
 * The same courier can be served by a rate table today and by an aggregator's
 * API tomorrow without a single screen or action changing.
 */
enum ShippingDriver: string
{
    /** Rates from `shipping_rates`, tracking typed in by the branch. */
    case Manual = 'manual';

    case Biteship = 'biteship';

    case RajaOngkir = 'rajaongkir';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual (tarif tabel)',
            self::Biteship => 'Biteship',
            self::RajaOngkir => 'RajaOngkir',
        };
    }

    /**
     * Whether a shipment under this driver can be tracked by asking somebody.
     *
     * A manual courier cannot: its timeline is what the branch typed, and
     * `SyncShipmentTracking` has nobody to ask.
     */
    public function supportsRemoteTracking(): bool
    {
        return $this !== self::Manual;
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
