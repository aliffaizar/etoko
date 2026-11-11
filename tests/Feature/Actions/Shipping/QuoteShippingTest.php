<?php

use App\Actions\Shipping\QuoteShipping;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingResult;
use App\Services\Shipping\Drivers\BiteshipProvider;
use App\Services\Shipping\ShippingProvider;

/*
 * Every way the shop can send a parcel, and what each costs (P2-E2-T2).
 *
 * The action the checkout screen, `PlaceOrder` and the panel's dispatch form
 * all ask, so that a customer shown one figure is charged that figure. Two
 * things are being guarded here: the **ordering** of the options a shopper
 * compares, and the difference between "nobody goes there" and "a provider is
 * down" — an outage that read as an unserved address would be a lie the
 * customer would believe.
 */

beforeEach(function () {
    $this->action = app(QuoteShipping::class);
    $this->origin = Branch::factory()->default()->create();
});

function quoteRequestTo(Branch $origin, int $weightGram = 1_000): ShippingQuoteRequest
{
    return new ShippingQuoteRequest(
        origin: $origin,
        weightGram: $weightGram,
        districtName: 'Coblong',
        cityName: 'Bandung',
        provinceName: 'Jawa Barat',
        postalCode: '40132',
    );
}

/**
 * A courier with one priced service.
 *
 * @return array{0: Courier, 1: CourierService}
 */
function pricedCourier(string $name, int $cost, int $sortOrder = 0, bool $express = false): array
{
    $courier = Courier::factory()->create(['name' => $name, 'sort_order' => $sortOrder]);

    $service = $express
        ? CourierService::factory()->forCourier($courier)->express()->create()
        : CourierService::factory()->forCourier($courier)->create();

    ShippingRate::factory()->forService($service)->priced($cost)->create();

    return [$courier, $service];
}

describe('the options a shopper compares', function () {
    it('offers every active courier, cheapest first', function () {
        pricedCourier('Dear Courier', 40_000);
        pricedCourier('Cheap Courier', 12_000);
        pricedCourier('Middling Courier', 25_000);

        $quotes = $this->action->handle(quoteRequestTo($this->origin));

        expect(array_map(fn (ShippingQuote $quote): int => $quote->cost, $quotes))
            ->toBe([12_000, 25_000, 40_000]);
    });

    it('breaks a tie on price with the faster promise', function () {
        [$courier] = pricedCourier('One Courier', 20_000);
        $express = CourierService::factory()->forCourier($courier)->express()->create();
        ShippingRate::factory()->forService($express)->priced(20_000)->create();

        $quotes = $this->action->handle(quoteRequestTo($this->origin));

        expect($quotes)->toHaveCount(2)
            ->and($quotes[0]->serviceName)->toBe('Express')
            ->and($quotes[0]->etdMaxDay)->toBe(1);
    });

    it('sorts a service that promises nothing last within its price', function () {
        [$courier] = pricedCourier('One Courier', 20_000);
        $vague = CourierService::factory()->forCourier($courier)->withoutEstimate()->create(['name' => 'Kargo']);
        ShippingRate::factory()->forService($vague)->priced(20_000)->create();

        $quotes = $this->action->handle(quoteRequestTo($this->origin));

        expect($quotes[1]->serviceName)->toBe('Kargo');
    });

    it('leaves an inactive courier out', function () {
        [$courier] = pricedCourier('Switched Off', 10_000);
        $courier->update(['is_active' => false]);
        pricedCourier('Still On', 30_000);

        $quotes = $this->action->handle(quoteRequestTo($this->origin));

        expect($quotes)->toHaveCount(1)->and($quotes[0]->courierName)->toBe('Still On');
    });

    it('answers with nothing when there is no address to quote against', function () {
        pricedCourier('One Courier', 20_000);

        $pickup = new ShippingQuoteRequest(origin: $this->origin, weightGram: 1_000);

        expect($this->action->handle($pickup))->toBe([]);
    });

    it('answers with nothing when no rule reaches the address', function () {
        Courier::factory()->create();

        expect($this->action->handle(quoteRequestTo($this->origin)))->toBe([]);
    });
});

describe('a provider that will not answer', function () {
    /**
     * A driver that always refuses, standing in for an aggregator that is down.
     *
     * Bound into the container in place of `BiteshipProvider`, which is the
     * seam `ShippingManager` was built with: it resolves providers through the
     * container precisely so a test can replace one.
     */
    beforeEach(function () {
        app()->instance(BiteshipProvider::class, new class implements ShippingProvider
        {
            public function quote(Courier $courier, ShippingQuoteRequest $request): array
            {
                throw ShippingFailedException::providerUnreachable();
            }

            public function createShipment(Shipment $shipment): ShipmentResult
            {
                throw ShippingFailedException::providerUnreachable();
            }

            public function track(Shipment $shipment): TrackingResult
            {
                throw ShippingFailedException::providerUnreachable();
            }
        });
    });

    it('still sells through the couriers that do answer', function () {
        Courier::factory()->biteship()->create(['name' => 'Aggregator']);
        pricedCourier('Own Rates', 18_000);

        $quotes = $this->action->handle(quoteRequestTo($this->origin));

        expect($quotes)->toHaveCount(1)
            ->and($quotes[0]->courierName)->toBe('Own Rates');
    });

    it('calls an outage an outage rather than an address nobody serves', function () {
        Courier::factory()->biteship()->create();

        expect(fn () => $this->action->handle(quoteRequestTo($this->origin)))
            ->toThrow(ShippingFailedException::class, 'tidak dapat dihubungi');
    });
});

describe('quoting the one service a customer chose', function () {
    it('prices it against the real address and weight', function () {
        [, $service] = pricedCourier('One Courier', 15_000);
        ShippingRate::query()->update(['price_per_kg' => 5_000]);

        $quote = $this->action->forService($service, quoteRequestTo($this->origin, weightGram: 3_000));

        expect($quote->cost)->toBe(15_000 + (2 * 5_000));
    });

    it('refuses a service the shop has switched off', function () {
        [, $service] = pricedCourier('One Courier', 15_000);
        $service->update(['is_active' => false]);

        expect(fn () => $this->action->forService($service, quoteRequestTo($this->origin)))
            ->toThrow(ShippingFailedException::class, 'sedang tidak tersedia');
    });

    it('refuses a service whose courier has been switched off', function () {
        [$courier, $service] = pricedCourier('One Courier', 15_000);
        $courier->update(['is_active' => false]);

        expect(fn () => $this->action->forService($service->fresh(), quoteRequestTo($this->origin)))
            ->toThrow(ShippingFailedException::class, 'sedang tidak tersedia');
    });

    it('refuses with the destination named when no rule reaches it', function () {
        $service = CourierService::factory()->create();

        expect(fn () => $this->action->forService($service, quoteRequestTo($this->origin)))
            ->toThrow(ShippingFailedException::class, 'Coblong, Bandung, Jawa Barat');
    });

    it('refuses when there is no destination at all', function () {
        [, $service] = pricedCourier('One Courier', 15_000);

        $pickup = new ShippingQuoteRequest(origin: $this->origin, weightGram: 1_000);

        expect(fn () => $this->action->forService($service, $pickup))
            ->toThrow(ShippingFailedException::class, 'belum lengkap');
    });
});
