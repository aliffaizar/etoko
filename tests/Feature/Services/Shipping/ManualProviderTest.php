<?php

use App\Enums\ShippingRateScope;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Drivers\ManualProvider;

/*
 * The shop's own price list (P2-E2-T2).
 *
 * The whole of this file is about one question — **which rule prices this
 * parcel?** — because the answer must not depend on which row was saved first.
 * `ManualProvider` ranks the rules that match: most specific destination
 * first, then a rule written for this branch over one written for any, then
 * the cheaper of two that are otherwise equal.
 *
 * Destinations are matched on the **names** the customer typed (ADR-043), not
 * on region ids, so the tests here compare text the way the application has to.
 */

beforeEach(function () {
    $this->provider = app(ManualProvider::class);
    $this->origin = Branch::factory()->default()->create();
    $this->courier = Courier::factory()->create(['name' => 'JNE']);
    $this->service = CourierService::factory()->forCourier($this->courier)->create(['name' => 'Reguler']);
});

/**
 * A quote request for one destination, 1 kg unless told otherwise.
 */
function parcelTo(
    Branch $origin,
    string $city = 'Bandung',
    string $province = 'Jawa Barat',
    string $district = 'Coblong',
    int $weightGram = 1_000,
): ShippingQuoteRequest {
    return new ShippingQuoteRequest(
        origin: $origin,
        weightGram: $weightGram,
        districtName: $district,
        cityName: $city,
        provinceName: $province,
        postalCode: '40132',
    );
}

describe('pricing one rule', function () {
    it('charges the flat price for a parcel inside the minimum weight', function () {
        ShippingRate::factory()->forService($this->service)->priced(18_000, 7_000, 1_000)->create();

        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin, weightGram: 800));

        expect($quotes)->toHaveCount(1)
            ->and($quotes[0]->cost)->toBe(18_000)
            ->and($quotes[0]->courierServiceId)->toBe($this->service->getKey());
    });

    it('charges a further whole kilogram for each kilogram over the minimum, rounded up', function () {
        ShippingRate::factory()->forService($this->service)->priced(18_000, 7_000, 1_000)->create();

        // 2.4 kg: the flat price covers the first kilogram, the remaining
        // 1.4 kg is charged as two — couriers round up, so the shop must.
        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin, weightGram: 2_400));

        expect($quotes[0]->cost)->toBe(18_000 + (2 * 7_000));
    });

    it('charges the flat price at any weight when the rule has no per-kilogram price', function () {
        ShippingRate::factory()->forService($this->service)->priced(12_000)->create();

        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin, weightGram: 9_000));

        expect($quotes[0]->cost)->toBe(12_000);
    });

    it('carries the service own delivery estimate into the quote', function () {
        ShippingRate::factory()->forService($this->service)->priced(10_000)->create();

        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin));

        expect($quotes[0]->etdMinDay)->toBe($this->service->etd_min_day)
            ->and($quotes[0]->etdMaxDay)->toBe($this->service->etd_max_day)
            ->and($quotes[0]->displayName())->toBe('JNE — Reguler');
    });
});

describe('choosing between rules', function () {
    it('prefers a district rule over the city, province and catch-all rules it sits inside', function () {
        ShippingRate::factory()->forService($this->service)->priced(30_000)->create();
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::Province, 'Jawa Barat')->priced(25_000)->create();
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(22_000)->create();
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::District, 'Coblong')->priced(40_000)->create();

        // The district rule wins although it is the *dearest*: specificity is
        // the point of the column, and a rule written for one district was
        // written to override the ones around it.
        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(40_000);
    });

    it('falls back to the next widest rule when the narrower one names another place', function () {
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::Province, 'Jawa Barat')->priced(25_000)->create();
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Surabaya')->priced(19_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(25_000);
    });

    it('prefers the branch own price list over a rule written for any origin', function () {
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(22_000)->create();
        ShippingRate::factory()->forService($this->service)->fromBranch($this->origin)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(28_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(28_000);
    });

    it('ignores another branch price list', function () {
        $other = Branch::factory()->create();

        ShippingRate::factory()->forService($this->service)->priced(30_000)->create();
        ShippingRate::factory()->forService($this->service)->fromBranch($other)->priced(9_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(30_000);
    });

    it('resolves two equally specific rules in the customer favour', function () {
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(26_000)->create();
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(21_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(21_000);
    });

    it('applies a rule written for every service when the service has none of its own', function () {
        ShippingRate::factory()->priced(16_000)->create(['courier_service_id' => null]);

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(16_000);
    });

    it('prefers a rule written for this service over one written for every service', function () {
        ShippingRate::factory()->priced(16_000)->create(['courier_service_id' => null]);
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, 'Bandung')->priced(24_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin))[0]->cost)->toBe(24_000);
    });
});

describe('matching the destination a customer typed', function () {
    it('matches regardless of case and surrounding space', function () {
        ShippingRate::factory()->forService($this->service)
            ->toDestination(ShippingRateScope::City, '  kota BANDUNG ')->priced(20_000)->create();

        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin, city: 'Kota Bandung'));

        expect($quotes)->toHaveCount(1)->and($quotes[0]->cost)->toBe(20_000);
    });

    it('treats a rule with a scope but no destination name as matching nothing', function () {
        ShippingRate::factory()->forService($this->service)->priced(20_000)
            ->create(['destination_type' => ShippingRateScope::City, 'destination_name' => null]);

        expect($this->provider->quote($this->courier, parcelTo($this->origin)))->toBe([]);
    });
});

describe('what is left out', function () {
    it('leaves a service with no matching rule out of the quote rather than quoting it free', function () {
        $priced = CourierService::factory()->forCourier($this->courier)->express()->create();
        ShippingRate::factory()->forService($priced)->priced(30_000)->create();

        $quotes = $this->provider->quote($this->courier, parcelTo($this->origin));

        expect($quotes)->toHaveCount(1)
            ->and($quotes[0]->courierServiceId)->toBe($priced->getKey());
    });

    it('ignores an inactive rule', function () {
        ShippingRate::factory()->forService($this->service)->priced(20_000)->inactive()->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin)))->toBe([]);
    });

    it('ignores an inactive service', function () {
        $this->service->update(['is_active' => false]);
        ShippingRate::factory()->forService($this->service)->priced(20_000)->create();

        expect($this->provider->quote($this->courier, parcelTo($this->origin)))->toBe([]);
    });

    it('quotes nothing when there is no destination to quote against', function () {
        ShippingRate::factory()->forService($this->service)->priced(20_000)->create();

        $pickup = new ShippingQuoteRequest(origin: $this->origin, weightGram: 1_000);

        expect($this->provider->quote($this->courier, $pickup))->toBe([]);
    });
});

describe('dispatching and tracking', function () {
    it('books nothing and hands back the waybill the branch typed', function () {
        $shipment = Shipment::factory()->dispatched('JNE12345')->create();

        $result = $this->provider->createShipment($shipment);

        expect($result->wasBooked())->toBeFalse()
            ->and($result->trackingNumber)->toBe('JNE12345');
    });

    it('refuses to be asked where a manually managed parcel is', function () {
        $shipment = Shipment::factory()->dispatched()->create();

        expect(fn () => $this->provider->track($shipment))
            ->toThrow(ShippingFailedException::class);
    });
});
