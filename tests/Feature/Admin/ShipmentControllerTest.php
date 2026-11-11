<?php

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\ShippingRate;
use App\Models\User;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingEvent;
use App\Services\Shipping\Data\TrackingResult;
use App\Services\Shipping\Drivers\BiteshipProvider;
use App\Services\Shipping\ShippingProvider;

/*
 * Sending an order's goods out, from the panel (P2-E2-T4, T6, T7).
 *
 * The writes have no screen of their own: they are made from the order's
 * detail page, because that is where somebody is standing when they decide to
 * send it. What is checked here is the layer the actions sit behind — who may
 * reach them, what the screen is told, and that another branch's row is **not
 * found** rather than refused (ADR-027), which is what keeps a branch from
 * learning it exists.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->central = User::factory()->adminPusat()->create();
    $this->branchAdmin = User::factory()->adminCabang($this->branch)->create();
    $this->cashier = User::factory()->kasir($this->branch)->create();
    $this->customer = User::factory()->customer()->create();

    $this->courier = Courier::factory()->create(['name' => 'JNE']);
    $this->service = CourierService::factory()->forCourier($this->courier)->create(['name' => 'Reguler']);
    ShippingRate::factory()->forService($this->service)->priced(18_000)->create();
});

/**
 * An order a branch is picking, with the parcel checkout opened for it.
 */
function orderReadyToSend(Branch $branch, ?CourierService $service = null): Order
{
    $order = Order::factory()->forBranch($branch)->processing()->create([
        'shipping_cost' => 18_000,
        'total_weight_gram' => 1_200,
    ]);

    if ($service instanceof CourierService) {
        Shipment::factory()->forOrder($order)->forService($service)->create();
    }

    return $order->fresh();
}

describe('what the order screen is told', function () {
    it('shows the parcel, its timeline and the couriers a packer may choose', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/orders/show')
                ->has('shipments', 1)
                ->where('shipments.0.courier_name', 'JNE')
                ->where('shipments.0.status', 'pending')
                ->where('shipments.0.can.manage', true)
                ->where('order.can.dispatch_shipment', true)
                ->where('blocked.dispatch', null)
                // Priced from the shop's own rate table, so the figure beside
                // a service is what it would have charged for it.
                ->where('courier_services.0.cost', 18_000)
                ->where('courier_services.0.display_name', 'JNE — Reguler')
                ->etc());
    });

    it('still offers the couriers when no rate reaches the address, without prices', function () {
        ShippingRate::query()->delete();
        $order = orderReadyToSend($this->branch, $this->service);

        // The goods are in somebody's hands and have to go out either way.
        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->has('courier_services', 1)
                ->where('courier_services.0.cost', null)
                ->etc());
    });

    it('explains why an order cannot be dispatched instead of hiding the form', function () {
        $order = Order::factory()->forBranch($this->branch)->paid()->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.dispatch_shipment', false)
                ->where('courier_services', [])
                ->where(
                    'blocked.dispatch',
                    'Pesanan berstatus "Sudah Dibayar" belum siap dikirim. Pindahkan ke "Diproses" terlebih dahulu.',
                )
                ->etc());
    });

    it('tells a Click and Collect screen the goods may be handed over', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup()->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.confirm_pickup', true)
                ->where('blocked.confirm_pickup', null)
                ->etc());
    });

    it('never shows staff the collection code', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        $response = $this->actingAs($this->branchAdmin)->get(route('admin.orders.show', $order));

        // The code is what the customer shows the counter. A screen that
        // displayed it would make the comparison meaningless.
        expect($response->content())->not->toContain('K7M2PQ');
    });
});

describe('dispatching', function () {
    it('records the waybill and ships the order', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->branchAdmin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
                'tracking_number' => 'jne-0012 3456',
                'notes' => 'Dibungkus bubble wrap.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $shipment = $order->fresh()->activeShipment();

        expect($shipment->tracking_number)->toBe('JNE00123456')
            ->and($shipment->status)->toBe(ShipmentStatus::PickedUp)
            ->and($shipment->notes)->toBe('Dibungkus bubble wrap.')
            ->and($shipment->dispatched_by)->toBe($this->branchAdmin->getKey())
            ->and($order->fresh()->status)->toBe(OrderStatus::Shipped);
    });

    it('demands a courier service', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.shipments.dispatch', $order), ['tracking_number' => 'JNE123'])
            ->assertSessionHasErrors('courier_service_id');

        expect($order->fresh()->status)->toBe(OrderStatus::Processing);
    });

    it('reports a refusal on the courier field and writes nothing', function () {
        $order = Order::factory()->forBranch($this->branch)->paid()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
                'tracking_number' => 'JNE123',
            ])
            // The policy refuses an order the state machine is not ready to
            // ship, so this never reaches the action.
            ->assertForbidden();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->shipments()->count())->toBe(0);
    });

    it('refuses a dispatch with no waybill from anybody, in words', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
            ])
            ->assertSessionHasErrors('courier_service_id');

        expect($order->fresh()->status)->toBe(OrderStatus::Processing);
    });

    it('is not found for another branch order', function () {
        $order = orderReadyToSend($this->otherBranch, $this->service);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
                'tracking_number' => 'JNE123',
            ])
            ->assertNotFound();
    });

    it('refuses a cashier, who holds no shipping permission', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->cashier)
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
                'tracking_number' => 'JNE123',
            ])
            ->assertForbidden();
    });

    it('refuses a customer outright', function () {
        $order = orderReadyToSend($this->branch, $this->service);

        $this->actingAs($this->customer)
            ->post(route('admin.orders.shipments.dispatch', $order), [
                'courier_service_id' => $this->service->getKey(),
                'tracking_number' => 'JNE123',
            ])
            ->assertForbidden();
    });
});

describe('moving a parcel along by hand', function () {
    beforeEach(function () {
        $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();
        $this->shipment = Shipment::factory()->forOrder($order)->forService($this->service)
            ->dispatched('JNE00123456')->create();
    });

    it('records the status and the branch own words', function () {
        $this->actingAs($this->branchAdmin)
            ->put(route('admin.shipments.status.update', $this->shipment), [
                'status' => 'in_transit',
                'description' => 'Masih di gudang JNE Bandung.',
                'location' => 'Bandung',
            ])
            ->assertRedirect();

        $event = $this->shipment->fresh()->trackingEvents()->latest('id')->first();

        expect($this->shipment->fresh()->status)->toBe(ShipmentStatus::InTransit)
            ->and($event->description)->toBe('Masih di gudang JNE Bandung.')
            ->and($event->source)->toBe(ShipmentTrackingEvent::SOURCE_BRANCH);
    });

    it('completes the delivery of the order when the parcel arrives', function () {
        $this->actingAs($this->branchAdmin)
            ->put(route('admin.shipments.status.update', $this->shipment), ['status' => 'delivered'])
            ->assertRedirect();

        expect($this->shipment->fresh()->order->status)->toBe(OrderStatus::Delivered);
    });

    it('refuses a status a branch may not set', function () {
        $this->actingAs($this->branchAdmin)
            ->put(route('admin.shipments.status.update', $this->shipment), ['status' => 'pending'])
            ->assertSessionHasErrors('status');

        expect($this->shipment->fresh()->status)->toBe(ShipmentStatus::PickedUp);
    });

    it('is not found for another branch parcel', function () {
        $order = Order::factory()->forBranch($this->otherBranch)->status(OrderStatus::Shipped)->create();
        $elsewhere = Shipment::factory()->forOrder($order)->forService($this->service)->dispatched()->create();

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.shipments.status.update', $elsewhere), ['status' => 'delivered'])
            ->assertNotFound();
    });

    it('lets central staff act on any branch parcel', function () {
        $this->actingAs($this->central)
            ->put(route('admin.shipments.status.update', $this->shipment), ['status' => 'in_transit'])
            ->assertRedirect();

        expect($this->shipment->fresh()->status)->toBe(ShipmentStatus::InTransit);
    });
});

describe('asking the courier now', function () {
    it('applies what the courier reports', function () {
        $courier = Courier::factory()->biteship()->create();
        $service = CourierService::factory()->forCourier($courier)->create();
        $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();
        $shipment = Shipment::factory()->forOrder($order)->forService($service)->inTransit('JNE999')->create();

        app()->instance(BiteshipProvider::class, new class implements ShippingProvider
        {
            public function quote(Courier $courier, ShippingQuoteRequest $request): array
            {
                return [];
            }

            public function createShipment(Shipment $shipment): ShipmentResult
            {
                return ShipmentResult::manual($shipment->tracking_number);
            }

            public function track(Shipment $shipment): TrackingResult
            {
                return new TrackingResult(
                    status: ShipmentStatus::Delivered,
                    events: [new TrackingEvent(ShipmentStatus::Delivered, 'Diterima Budi', now())],
                );
            }
        });

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.shipments.tracking.refresh', $shipment))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Delivered)
            ->and($shipment->fresh()->tracking_synced_at)->not->toBeNull();
    });

    it('says so in words when there is nobody to ask', function () {
        $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();
        $shipment = Shipment::factory()->forOrder($order)->forService($this->service)->inTransit()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.shipments.tracking.refresh', $shipment))
            ->assertSessionHasErrors('tracking');
    });

    it('passes a provider refusal on to the staff member', function () {
        $courier = Courier::factory()->biteship()->create();
        $service = CourierService::factory()->forCourier($courier)->create();
        $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();
        $shipment = Shipment::factory()->forOrder($order)->forService($service)->inTransit('JNE999')->create();

        app()->instance(BiteshipProvider::class, new class implements ShippingProvider
        {
            public function quote(Courier $courier, ShippingQuoteRequest $request): array
            {
                return [];
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

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.shipments.tracking.refresh', $shipment))
            ->assertSessionHasErrors('tracking');

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::InTransit);
    });
});

describe('handing a Click and Collect order over', function () {
    beforeEach(function () {
        $this->pickupOrder = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();
    });

    it('completes the order when the code matches', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.pickup.confirm', $this->pickupOrder), ['pickup_code' => 'k7m2pq'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->pickupOrder->fresh()->status)->toBe(OrderStatus::Completed);
    });

    it('reports a wrong code on the field and completes nothing', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.pickup.confirm', $this->pickupOrder), ['pickup_code' => 'WRONG1'])
            ->assertSessionHasErrors('pickup_code');

        expect($this->pickupOrder->fresh()->status)->toBe(OrderStatus::ReadyForPickup);
    });

    it('demands a code unless the staff member waives it', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.pickup.confirm', $this->pickupOrder), [])
            ->assertSessionHasErrors('pickup_code');

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.pickup.confirm', $this->pickupOrder), [
                'override_missing_code' => true,
                'note' => 'KTP diperiksa.',
            ])
            ->assertSessionHasNoErrors();

        expect($this->pickupOrder->fresh()->status)->toBe(OrderStatus::Completed);
    });

    it('is not found for another branch order', function () {
        $elsewhere = Order::factory()->forBranch($this->otherBranch)->readyForPickup('K7M2PQ')->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.pickup.confirm', $elsewhere), ['pickup_code' => 'K7M2PQ'])
            ->assertNotFound();
    });
});
