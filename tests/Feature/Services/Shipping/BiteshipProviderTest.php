<?php

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Drivers\BiteshipProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Biteship, behind the same interface as the shop's own rate table
 * (P2-E2-T6).
 *
 * Driven entirely through `Http::fake()`, which is deliberate: the project has
 * no Biteship key, and a driver that can only be exercised against a live
 * sandbox is a driver that is not tested. What is checked here is the three
 * things only this class knows — the request bodies, the mapping between their
 * vocabulary and ours, and what it does with an answer it cannot use.
 */

const BITESHIP_KEY = 'biteship_test_FAKEKEY';

beforeEach(function () {
    config()->set('services.biteship.api_key', BITESHIP_KEY);
    config()->set('services.biteship.base_url', 'https://api.biteship.test');

    $this->provider = app(BiteshipProvider::class);
    $this->origin = Branch::factory()->default()->create([
        'name' => 'Cabang Bandung',
        'address_line' => 'Jl. Merdeka 1',
        'city_name' => 'Bandung',
        'province_name' => 'Jawa Barat',
        'postal_code' => '40111',
        'phone' => '022000111',
    ]);
    $this->courier = Courier::factory()->biteship('jne')->create(['name' => 'JNE']);
    $this->service = CourierService::factory()->forCourier($this->courier)
        ->create(['code' => 'REG', 'name' => 'Reguler']);
});

function biteshipParcel(Branch $origin, int $weightGram = 1_000): ShippingQuoteRequest
{
    return new ShippingQuoteRequest(
        origin: $origin,
        weightGram: $weightGram,
        districtName: 'Menteng',
        cityName: 'Jakarta Pusat',
        provinceName: 'DKI Jakarta',
        postalCode: '10310',
        itemValue: 500_000,
    );
}

describe('quoting', function () {
    it('asks for this courier only and quotes the services the shop has rows for', function () {
        Http::fake([
            '*/v1/rates/couriers' => Http::response([
                'pricing' => [
                    [
                        'courier_service_code' => 'reg',
                        'price' => 23_000,
                        'shipment_duration_range' => ['min' => 2, 'max' => 4],
                    ],
                    // A service the shop has no row for cannot be chosen: there
                    // would be nothing to snapshot and nothing to track with.
                    ['courier_service_code' => 'JTR', 'price' => 9_000],
                ],
            ]),
        ]);

        $quotes = $this->provider->quote($this->courier, biteshipParcel($this->origin));

        expect($quotes)->toHaveCount(1)
            ->and($quotes[0]->courierServiceId)->toBe($this->service->getKey())
            ->and($quotes[0]->cost)->toBe(23_000)
            // The provider's own estimate wins over the service's, because it
            // is the one quoting this parcel.
            ->and($quotes[0]->etdMinDay)->toBe(2)
            ->and($quotes[0]->etdMaxDay)->toBe(4);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('authorization', BITESHIP_KEY)
                && $request['couriers'] === 'jne'
                && $request['destination_postal_code'] === '10310'
                && $request['origin_postal_code'] === '40111'
                && $request['items'][0]['weight'] === 1_000;
        });
    });

    it('drops a quote whose price cannot be read', function () {
        Http::fake([
            '*/v1/rates/couriers' => Http::response([
                'pricing' => [['courier_service_code' => 'REG', 'price' => null]],
            ]),
        ]);

        expect($this->provider->quote($this->courier, biteshipParcel($this->origin)))->toBe([]);
    });

    it('refuses in the provider own words when it turns the request down', function () {
        Http::fake([
            '*/v1/rates/couriers' => Http::response(['error' => 'destination area not found'], 422),
        ]);

        expect(fn () => $this->provider->quote($this->courier, biteshipParcel($this->origin)))
            ->toThrow(ShippingFailedException::class, 'destination area not found');
    });

    it('refuses with a sentence rather than sending an unauthenticated request', function () {
        config()->set('services.biteship.api_key', null);
        Http::fake();

        expect(fn () => $this->provider->quote($this->courier, biteshipParcel($this->origin)))
            ->toThrow(ShippingFailedException::class, 'BITESHIP_API_KEY');

        Http::assertNothingSent();
    });

    it('asks nobody when there is no destination', function () {
        Http::fake();

        $pickup = new ShippingQuoteRequest(origin: $this->origin, weightGram: 1_000);

        expect($this->provider->quote($this->courier, $pickup))->toBe([]);
        Http::assertNothingSent();
    });
});

describe('booking a parcel', function () {
    beforeEach(function () {
        $this->order = Order::factory()->forBranch($this->origin)->processing()->create([
            'order_number' => 'ORD-20261108-0001',
            'shipping_recipient' => 'Budi Santoso',
            'shipping_phone' => '0811222333',
            'shipping_address_line' => 'Jl. Sudirman 10',
            'shipping_postal_code' => '10310',
        ]);

        /** @var ProductVariant $variant */
        $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
        OrderItem::factory()->forOrder($this->order)->forVariant($variant)->quantity(2)->create();

        $this->shipment = Shipment::factory()->forOrder($this->order->fresh())
            ->forService($this->service)->create();
    });

    it('comes back with the waybill the aggregator issued', function () {
        Http::fake([
            '*/v1/orders' => Http::response([
                'id' => 'bite-order-1',
                'courier' => ['waybill_id' => 'JNE0099887766'],
            ]),
        ]);

        $result = $this->provider->createShipment($this->shipment);

        expect($result->wasBooked())->toBeTrue()
            ->and($result->trackingNumber)->toBe('JNE0099887766')
            ->and($result->externalId)->toBe('bite-order-1');

        Http::assertSent(function (Request $request): bool {
            return $request['reference_id'] === 'ORD-20261108-0001'
                && $request['courier_company'] === 'jne'
                && $request['courier_type'] === 'REG'
                && $request['destination_contact_name'] === 'Budi Santoso'
                // Declared per line, because aggregators price and insure per
                // item and a parcel the courier opens must match the manifest.
                && count($request['items']) === 1
                && $request['items'][0]['quantity'] === 2;
        });
    });

    it('treats a booking with no waybill yet as booked but unlabelled', function () {
        Http::fake(['*/v1/orders' => Http::response(['id' => 'bite-order-1'])]);

        $result = $this->provider->createShipment($this->shipment);

        expect($result->wasBooked())->toBeFalse()
            ->and($result->trackingNumber)->toBeNull();
    });

    it('refuses a booking that comes back with neither a waybill nor an id', function () {
        Http::fake(['*/v1/orders' => Http::response(['success' => true])]);

        expect(fn () => $this->provider->createShipment($this->shipment))
            ->toThrow(ShippingFailedException::class, 'tidak memuat nomor resi');
    });

    it('turns a dead connection into a sentence', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        expect(fn () => $this->provider->createShipment($this->shipment))
            ->toThrow(ShippingFailedException::class, 'tidak dapat dihubungi');
    });
});

describe('tracking', function () {
    beforeEach(function () {
        $order = Order::factory()->forBranch($this->origin)->status(OrderStatus::Shipped)->create();
        $this->shipment = Shipment::factory()->forOrder($order)->forService($this->service)
            ->inTransit('JNE0099887766')->create();
    });

    it('asks by the aggregator own booking id when there is one', function () {
        $this->shipment->update(['external_id' => 'bite-order-1']);

        Http::fake([
            '*/v1/trackings/bite-order-1' => Http::response([
                'status' => 'delivered',
                'history' => [
                    ['status' => 'picked', 'note' => 'Dijemput kurir', 'updated_at' => '2026-11-07T09:00:00+07:00'],
                    ['status' => 'delivered', 'note' => 'Diterima Budi', 'updated_at' => '2026-11-08T14:20:00+07:00'],
                ],
            ]),
        ]);

        $result = $this->provider->track($this->shipment->fresh());

        expect($result->status)->toBe(ShipmentStatus::Delivered)
            ->and($result->events)->toHaveCount(2)
            // Their vocabulary is mapped here, so no courier's dialect reaches
            // `RecordShipmentTracking` or the customer's timeline.
            ->and($result->orderedEvents()[0]->status)->toBe(ShipmentStatus::PickedUp)
            ->and($result->orderedEvents()[0]->description)->toBe('Dijemput kurir')
            ->and($result->orderedEvents()[0]->eventAt->format('H:i'))->toBe('09:00');
    });

    it('asks by waybill and courier code when the branch typed the number in', function () {
        Http::fake(['*' => Http::response(['status' => 'in_transit'])]);

        $this->provider->track($this->shipment);

        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/v1/trackings/JNE0099887766/couriers/jne',
        ));
    });

    it('keeps the reported position when the answer carries no history', function () {
        Http::fake(['*' => Http::response(['status' => 'in_transit'])]);

        $result = $this->provider->track($this->shipment);

        expect($result->status)->toBe(ShipmentStatus::InTransit)
            // One event rather than none: a timeline that showed nothing while
            // the parcel's status said it had moved would read as a bug.
            ->and($result->events)->toHaveCount(1);
    });

    it('ignores a scan whose status means nothing here', function () {
        Http::fake(['*' => Http::response([
            'status' => 'in_transit',
            'history' => [
                ['status' => 'weighed_at_warehouse', 'note' => 'Ditimbang', 'updated_at' => '2026-11-07T09:00:00+07:00'],
                ['status' => 'in_transit', 'note' => 'Berangkat', 'updated_at' => '2026-11-07T10:00:00+07:00'],
            ],
        ])]);

        $result = $this->provider->track($this->shipment);

        expect($result->events)->toHaveCount(1)
            ->and($result->events[0]->description)->toBe('Berangkat');
    });

    it('refuses an answer whose own status means nothing here', function () {
        Http::fake(['*' => Http::response(['status' => 'something_new'])]);

        expect(fn () => $this->provider->track($this->shipment))
            ->toThrow(ShippingFailedException::class, 'tidak dikenali');
    });
});
