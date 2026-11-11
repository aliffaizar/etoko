<?php

use App\Enums\ShippingDriver;
use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipping\Drivers\BiteshipProvider;
use App\Services\Shipping\Drivers\ManualProvider;
use App\Services\Shipping\ShippingManager;

/*
 * Which code carries out a courier's work (P2-E2-T2, ADR-004).
 *
 * The one place that knows which class serves which `couriers.driver` value.
 * Everything above it is written against `ShippingProvider` and never names a
 * driver, which is what made adding Biteship a matter of one `match` arm.
 */

beforeEach(function () {
    $this->manager = app(ShippingManager::class);
});

it('serves a courier with the driver its row names', function () {
    $manual = Courier::factory()->create();
    $aggregated = Courier::factory()->biteship()->create();

    expect($this->manager->for($manual))->toBeInstanceOf(ManualProvider::class)
        ->and($this->manager->for($aggregated))->toBeInstanceOf(BiteshipProvider::class);
});

it('refuses a driver the application cannot serve yet, in words', function () {
    expect(fn () => $this->manager->driver(ShippingDriver::RajaOngkir))
        ->toThrow(ShippingFailedException::class, 'RajaOngkir belum tersedia');
});

it('reads a parcel driver from the live courier row rather than its snapshot', function () {
    $courier = Courier::factory()->create();
    $shipment = Shipment::factory()->create(['courier_id' => $courier->getKey()]);

    // A shop that moves a courier from the rate table to an aggregator wants
    // its parcels tracked by the provider that is live, not the one that was.
    $courier->update(['driver' => ShippingDriver::Biteship]);

    expect($this->manager->forShipment($shipment->fresh()))->toBeInstanceOf(BiteshipProvider::class);
});

it('refuses a parcel whose courier row has gone', function () {
    $shipment = Shipment::factory()->create(['courier_name' => 'JNE']);
    $shipment->courier?->delete();

    expect(fn () => $this->manager->forShipment($shipment->fresh()))
        ->toThrow(ShippingFailedException::class, 'sudah tidak terdaftar');
});

it('hands out a fresh provider each time, so a swapped binding is honoured', function () {
    $courier = Courier::factory()->create();

    $first = $this->manager->for($courier);
    app()->instance(ManualProvider::class, $replacement = new ManualProvider);

    expect($this->manager->for($courier))->toBe($replacement)
        ->and($first)->not->toBe($replacement);
});
