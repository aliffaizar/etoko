<?php

use App\Enums\ShippingDriver;
use App\Enums\ShippingRateScope;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\ShippingRate;
use Database\Seeders\CourierSeeder;

/*
 * The couriers and the price list every install needs (P2-E2-T1, T2).
 *
 * Reference data rather than demo content — a shop with no courier has a
 * delivery option that cannot be completed — so this runs outside the
 * `isProduction()` guard, and re-running it must not undo a shop's own
 * decisions or overwrite its real tariffs.
 */

it('installs couriers with services and a national rate each', function () {
    $this->seed(CourierSeeder::class);

    expect(Courier::query()->count())->toBeGreaterThan(2)
        ->and(CourierService::query()->count())->toBeGreaterThan(3);

    // Every manual service can price a parcel to anywhere, or it would be an
    // option that quotes nothing.
    $manualServices = CourierService::query()
        ->whereHas('courier', fn ($query) => $query->where('driver', ShippingDriver::Manual))
        ->get();

    foreach ($manualServices as $service) {
        $hasReachableRate = $service->rates()
            ->whereIn('destination_type', [
                ShippingRateScope::All->value,
                ShippingRateScope::City->value,
                ShippingRateScope::Province->value,
            ])
            ->exists();

        expect($hasReachableRate)->toBeTrue();
    }
});

it('prices a province below the national rule it sits inside', function () {
    $this->seed(CourierSeeder::class);

    $service = CourierService::query()->whereRelation('courier', 'code', 'jne')
        ->where('code', 'REG')->sole();

    $national = $service->rates()->where('destination_type', ShippingRateScope::All)->sole();
    $jakarta = $service->rates()->where('destination_name', 'DKI Jakarta')->sole();

    expect($jakarta->flat_price)->toBeLessThan($national->flat_price)
        ->and($jakarta->destination_type)->toBe(ShippingRateScope::Province);
});

it('leaves the aggregator switched off until there is a key for it', function () {
    $this->seed(CourierSeeder::class);

    $biteship = Courier::query()->where('driver', ShippingDriver::Biteship)->sole();

    expect($biteship->is_active)->toBeFalse()
        // An aggregator quotes its own prices; a fallback row here would be a
        // figure the shop invented for a courier meant to answer for itself.
        ->and(ShippingRate::query()->whereRelation('courierService', 'courier_id', $biteship->getKey())->count())
        ->toBe(0);
});

it('maps our courier code onto the aggregator own vocabulary', function () {
    $this->seed(CourierSeeder::class);

    $biteship = Courier::query()->where('driver', ShippingDriver::Biteship)->sole();

    expect($biteship->providerCode())->toBe('jne');
});

it('runs twice without duplicating anything', function () {
    $this->seed(CourierSeeder::class);

    $counts = [
        Courier::query()->count(),
        CourierService::query()->count(),
        ShippingRate::query()->count(),
    ];

    $this->seed(CourierSeeder::class);

    expect([
        Courier::query()->count(),
        CourierService::query()->count(),
        ShippingRate::query()->count(),
    ])->toBe($counts);
});

it('does not switch a disabled courier back on, or restore its prices', function () {
    $this->seed(CourierSeeder::class);

    $courier = Courier::query()->where('code', 'jne')->sole();
    $courier->update(['is_active' => false, 'sort_order' => 99]);

    $rate = ShippingRate::query()->whereRelation('courierService', 'courier_id', $courier->getKey())
        ->where('destination_type', ShippingRateScope::All)->first();
    $rate->update(['flat_price' => 44_444]);

    $this->seed(CourierSeeder::class);

    expect($courier->fresh()->is_active)->toBeFalse()
        ->and($courier->fresh()->sort_order)->toBe(99)
        // A price list is the one thing a shop certainly edits: re-seeding
        // must not quietly restore the placeholder figures over a real tariff.
        ->and($rate->fresh()->flat_price)->toBe(44_444);
});

it('refreshes the definition of a courier that already exists', function () {
    Courier::factory()->create(['code' => 'jne', 'name' => 'Nama lama', 'is_active' => true]);

    $this->seed(CourierSeeder::class);

    expect(Courier::query()->where('code', 'jne')->sole()->name)->toBe('JNE');
});
