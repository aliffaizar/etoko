<?php

use App\Actions\Shipping\DispatchShipment;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;

/*
 * Handing an order's goods to a courier (P2-E2-T4).
 *
 * Three things have to land together or not at all: the waybill number on the
 * shipment, the first row of the parcel's timeline, and the order's move to
 * `shipped`. An order marked shipped with no waybill is an order nobody can
 * trace, and a waybill on an order still "being processed" is goods that have
 * left with nothing saying so.
 */

beforeEach(function () {
    $this->action = app(DispatchShipment::class);
    $this->branch = Branch::factory()->default()->create();
    $this->staff = User::factory()->adminCabang($this->branch)->create();

    $this->courier = Courier::factory()->create(['name' => 'JNE']);
    $this->service = CourierService::factory()->forCourier($this->courier)->create(['name' => 'Reguler']);
});

/**
 * An order a branch is picking, with the parcel `CreateShipment` opened for it.
 */
function orderBeingPicked(Branch $branch, ?CourierService $service = null): Order
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

describe('the dispatch', function () {
    it('records the waybill, moves the parcel and ships the order', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        $shipment = $this->action->handle($order, $this->service, 'jne 0012 - 3456', $this->staff);

        expect($shipment->status)->toBe(ShipmentStatus::PickedUp)
            // Upper-cased and stripped of what people copy off a receipt, so
            // one parcel is not recorded twice under two spellings.
            ->and($shipment->tracking_number)->toBe('JNE00123456')
            ->and($shipment->shipped_at)->not->toBeNull()
            ->and($shipment->dispatched_by)->toBe($this->staff->getKey())
            ->and($order->fresh()->status)->toBe(OrderStatus::Shipped)
            ->and($order->fresh()->shipped_at)->not->toBeNull();
    });

    it('opens the parcel timeline with a row attributed to the branch', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        $shipment = $this->action->handle($order, $this->service, 'JNE123', $this->staff);

        $event = $shipment->trackingEvents()->sole();

        expect($event->status)->toBe(ShipmentStatus::PickedUp)
            ->and($event->source)->toBe(ShipmentTrackingEvent::SOURCE_BRANCH)
            ->and($event->description)->toContain('JNE');
    });

    it('writes the dispatch into the order own timeline with the waybill in it', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        $this->action->handle($order, $this->service, 'JNE123', $this->staff);

        expect($order->fresh()->statusHistories()->latest('id')->first()->note)
            ->toContain('JNE123')
            ->toContain('Reguler');
    });

    it('opens a parcel for an order whose delivery was arranged by hand', function () {
        $order = orderBeingPicked($this->branch);

        $shipment = $this->action->handle($order, $this->service, 'JNE123', $this->staff);

        expect($order->shipments()->count())->toBe(1)
            // The charge is what the order was billed, which is the only
            // figure anybody agreed to — never a fresh quote.
            ->and($shipment->cost)->toBe(18_000)
            ->and($shipment->weight_gram)->toBe(1_200)
            ->and($shipment->courier_name)->toBe('JNE')
            ->and($shipment->service_name)->toBe('Reguler');
    });

    it('does not re-price the order when the branch uses a different service', function () {
        $order = orderBeingPicked($this->branch, $this->service);
        $dearer = CourierService::factory()->forCourier($this->courier)->express()->create();

        $shipment = $this->action->handle($order, $dearer, 'JNE123', $this->staff);

        expect($shipment->cost)->toBe(18_000)
            ->and($order->fresh()->shipping_cost)->toBe(18_000)
            ->and($order->fresh()->grand_total)->toBe($order->grand_total)
            // The change of courier is recorded, which is where a question
            // about it gets answered.
            ->and($shipment->service_name)->toBe('Express');
    });

    it('keeps the snapshot of the courier as it was at dispatch', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        $shipment = $this->action->handle($order, $this->service, 'JNE123', $this->staff);

        $this->courier->update(['name' => 'JNE Express Indonesia']);

        expect($shipment->fresh()->courier_name)->toBe('JNE');
    });

    it('records the note the packer left', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        $shipment = $this->action->handle($order, $this->service, 'JNE123', $this->staff, 'Dibungkus bubble wrap.');

        expect($shipment->notes)->toBe('Dibungkus bubble wrap.');
    });
});

describe('what it refuses', function () {
    it('refuses an order that is not being picked yet', function () {
        $order = Order::factory()->forBranch($this->branch)->paid()->create();

        expect(fn () => $this->action->handle($order, $this->service, 'JNE123', $this->staff))
            ->toThrow(ShippingFailedException::class, 'belum siap dikirim');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('refuses an order that has already gone out', function () {
        $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();

        expect(fn () => $this->action->handle($order, $this->service, 'JNE123', $this->staff))
            ->toThrow(ShippingFailedException::class, 'sudah dikirim');
    });

    it('refuses a Click and Collect order', function () {
        $order = Order::factory()->forBranch($this->branch)->pickup()->processing()->create();

        expect(fn () => $this->action->handle($order, $this->service, 'JNE123', $this->staff))
            ->toThrow(ShippingFailedException::class, 'diambil sendiri di cabang');
    });

    it('refuses a service the shop has switched off', function () {
        $order = orderBeingPicked($this->branch, $this->service);
        $this->service->update(['is_active' => false]);

        expect(fn () => $this->action->handle($order, $this->service->fresh(), 'JNE123', $this->staff))
            ->toThrow(ShippingFailedException::class, 'sedang tidak tersedia');
    });

    it('refuses a dispatch with no waybill number from anybody', function () {
        $order = orderBeingPicked($this->branch, $this->service);

        expect(fn () => $this->action->handle($order, $this->service, null, $this->staff))
            ->toThrow(ShippingFailedException::class, 'Nomor resi belum ada');

        // Nothing was written: the order stays where it was, and the parcel is
        // still waiting at the branch.
        expect($order->fresh()->status)->toBe(OrderStatus::Processing)
            ->and($order->activeShipment()->status)->toBe(ShipmentStatus::Pending);
    });

    it('refuses a parcel that has already left the branch', function () {
        $order = orderBeingPicked($this->branch);
        Shipment::factory()->forOrder($order)->forService($this->service)->dispatched('FIRST123')->create();

        expect(fn () => $this->action->handle($order->fresh(), $this->service, 'SECOND456', $this->staff))
            ->toThrow(ShippingFailedException::class, 'sudah berstatus');

        expect($order->fresh()->shipments()->count())->toBe(1);
    });
});

describe('re-sending a parcel that came back', function () {
    it('opens a second shipment rather than reopening the first', function () {
        $order = orderBeingPicked($this->branch);
        Shipment::factory()->forOrder($order)->forService($this->service)->returned()->create();

        // A returned parcel leaves none open, so the order can go out again —
        // and the first journey stays on the record as its own row.
        $second = $this->action->handle($order->fresh(), $this->service, 'JNE999', $this->staff);

        expect($order->fresh()->shipments()->count())->toBe(2)
            ->and($second->tracking_number)->toBe('JNE999')
            ->and($order->fresh()->activeShipment()->getKey())->toBe($second->getKey());
    });
});
