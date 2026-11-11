<?php

use App\Actions\Shipping\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShippingQuote;

/*
 * Recording which courier an order's goods are going with (P2-E2-T3).
 *
 * Called by `PlaceOrder` inside its transaction: the figure in
 * `orders.shipping_cost` and the row that explains it have to land together,
 * or the order carries a delivery charge nobody can account for. It books
 * nothing — handing the parcel over is `DispatchShipment`.
 */

beforeEach(function () {
    $this->action = app(CreateShipment::class);
    $this->branch = Branch::factory()->default()->create();
    $this->service = CourierService::factory()->create(['name' => 'Reguler']);
    $this->service->load('courier');
});

function acceptedQuote(CourierService $service, int $cost = 18_000): ShippingQuote
{
    return ShippingQuote::forService($service, $cost);
}

it('opens a parcel waiting at the branch, from the quote the customer accepted', function () {
    $order = Order::factory()->forBranch($this->branch)->create(['total_weight_gram' => 1_400]);

    $shipment = $this->action->handle($order, acceptedQuote($this->service));

    expect($shipment->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->branch_id)->toBe($this->branch->getKey())
        ->and($shipment->cost)->toBe(18_000)
        ->and($shipment->weight_gram)->toBe(1_400)
        ->and($shipment->courier_name)->toBe($this->service->courier->name)
        ->and($shipment->service_name)->toBe('Reguler')
        ->and($shipment->tracking_number)->toBeNull();
});

it('keeps the snapshot of the service as it was quoted', function () {
    $order = Order::factory()->forBranch($this->branch)->create();

    $shipment = $this->action->handle($order, acceptedQuote($this->service));

    $this->service->update(['name' => 'Reguler (baru)']);

    expect($shipment->fresh()->service_name)->toBe('Reguler');
});

it('refuses an order that is collected at the branch', function () {
    $order = Order::factory()->forBranch($this->branch)->pickup()->create();

    expect(fn () => $this->action->handle($order, acceptedQuote($this->service)))
        ->toThrow(ShippingFailedException::class, 'tidak memerlukan pengiriman');
});

it('refuses a second parcel while one is still in play', function () {
    $order = Order::factory()->forBranch($this->branch)->create();
    Shipment::factory()->forOrder($order)->forService($this->service)->create();

    expect(fn () => $this->action->handle($order->fresh(), acceptedQuote($this->service)))
        ->toThrow(ShippingFailedException::class, 'sudah memiliki pengiriman yang berjalan');
});

it('allows a second parcel once the first has come back', function () {
    $order = Order::factory()->forBranch($this->branch)->create();
    Shipment::factory()->forOrder($order)->forService($this->service)->returned()->create();

    $second = $this->action->handle($order->fresh(), acceptedQuote($this->service));

    expect($order->fresh()->shipments()->count())->toBe(2)
        ->and($order->fresh()->activeShipment()->getKey())->toBe($second->getKey());
});
