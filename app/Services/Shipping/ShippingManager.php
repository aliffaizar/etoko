<?php

namespace App\Services\Shipping;

use App\Enums\ShippingDriver;
use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipping\Drivers\BiteshipProvider;
use App\Services\Shipping\Drivers\ManualProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the provider that carries out a courier's work (P2-E2-T2, ADR-004).
 *
 * The one place that knows which class serves which `couriers.driver` value.
 * Everything else — the actions, the controllers, the job — is written against
 * `ShippingProvider` and never names a driver, which is what made adding
 * Biteship in `P2-E2-T6` a matter of one `match` arm and one class.
 *
 * Providers come out of the container, so a test swaps one with
 * `$this->app->instance()` and a provider may take its own dependencies. They
 * are **not** cached per driver: a provider holds no per-parcel state, but
 * caching would silently keep a test's stub alive past the binding that
 * replaced it — the lesson `PaymentManager` records.
 *
 * `RajaOngkir` is a value the schema allows and the application cannot yet
 * serve, so it is refused here with a sentence rather than further down with a
 * container error. That is the difference between "we have not built this yet"
 * and a 500. See `SHIP-02` in docs/07-progress.md.
 */
final class ShippingManager
{
    public function __construct(private readonly Container $container) {}

    /**
     * The provider for a parcel, by the courier it was sent with.
     *
     * Read through the `courier` relation rather than the snapshot columns on
     * purpose: the snapshots describe how the parcel was *sent*, while the
     * driver is who to talk to *now* — and a shop that moves a courier from
     * the rate table to an aggregator wants its parcels tracked by the
     * provider that is live, not by the one that was.
     *
     * @throws ShippingFailedException when the courier row has been deleted.
     */
    public function forShipment(Shipment $shipment): ShippingProvider
    {
        $courier = $shipment->courier;

        if (! $courier instanceof Courier) {
            throw ShippingFailedException::unsupported(
                "Kurir \"{$shipment->courier_name}\" sudah tidak terdaftar, jadi pengiriman ini tidak dapat dilacak otomatis. "
                    .'Perbarui statusnya secara manual.',
            );
        }

        return $this->for($courier);
    }

    public function for(Courier $courier): ShippingProvider
    {
        return $this->driver($courier->driver);
    }

    public function driver(ShippingDriver $driver): ShippingProvider
    {
        return match ($driver) {
            ShippingDriver::Manual => $this->container->make(ManualProvider::class),
            ShippingDriver::Biteship => $this->container->make(BiteshipProvider::class),
            ShippingDriver::RajaOngkir => throw ShippingFailedException::unsupported(
                'Driver ekspedisi RajaOngkir belum tersedia. Pilih kurir lain.',
            ),
        };
    }
}
