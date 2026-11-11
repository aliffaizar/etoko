<?php

use App\Actions\Shipping\RecordShipmentTracking;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;
use App\Services\Shipping\Data\TrackingEvent;
use App\Services\Shipping\Data\TrackingResult;

/*
 * The only way a parcel's position changes (P2-E2-T4, T5, T6).
 *
 * Two guarantees carry everything above this action:
 *
 * - **Idempotency.** Couriers return their whole history on every call, so the
 *   ten-minute sweep must be able to apply the same forty scans forty times a
 *   day without writing them twice. The unique index on
 *   `(shipment_id, status, event_at)` is the natural key that makes a repeat a
 *   no-op.
 * - **Forward only.** Scans arrive out of order, so a late "in transit" must
 *   not un-deliver a parcel.
 */

beforeEach(function () {
    $this->action = app(RecordShipmentTracking::class);
    $this->branch = Branch::factory()->default()->create();
    $this->staff = User::factory()->adminCabang($this->branch)->create();
});

/**
 * A parcel on its way, belonging to an order that has been shipped.
 */
function parcelOnItsWay(Branch $branch, ShipmentStatus $status = ShipmentStatus::PickedUp): Shipment
{
    $order = Order::factory()->forBranch($branch)->status(OrderStatus::Shipped)->create();

    return Shipment::factory()->forOrder($order)->create([
        'status' => $status,
        'tracking_number' => 'JNE00123456',
        'shipped_at' => now()->subDay(),
    ]);
}

/**
 * @param  array<int, TrackingEvent>  $events
 */
function courierSays(ShipmentStatus $status, array $events = []): TrackingResult
{
    return new TrackingResult(status: $status, events: $events, rawPayload: ['status' => $status->value]);
}

describe('what a courier reports', function () {
    it('appends the scans and moves the parcel', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->handle($shipment, courierSays(ShipmentStatus::InTransit, [
            new TrackingEvent(ShipmentStatus::InTransit, 'Berangkat dari Bandung', now()->subHours(3), 'Bandung'),
            new TrackingEvent(ShipmentStatus::InTransit, 'Tiba di Jakarta', now()->subHour(), 'Jakarta'),
        ]));

        expect($updated->status)->toBe(ShipmentStatus::InTransit)
            ->and($updated->trackingEvents()->count())->toBe(2)
            ->and($this->action->appendedEvents)->toBe(2)
            ->and($updated->tracking_synced_at)->not->toBeNull()
            ->and($updated->raw_payload)->toBe(['status' => 'in_transit']);
    });

    it('records the same history twice without duplicating a single row', function () {
        $shipment = parcelOnItsWay($this->branch);
        $moment = now()->subHours(3);

        $result = courierSays(ShipmentStatus::InTransit, [
            new TrackingEvent(ShipmentStatus::InTransit, 'Berangkat dari Bandung', $moment, 'Bandung'),
        ]);

        $this->action->handle($shipment, $result);
        $this->action->handle($shipment->fresh(), $result);

        expect($shipment->fresh()->trackingEvents()->count())->toBe(1)
            ->and($this->action->appendedEvents)->toBe(0);
    });

    it('places a scan at the courier own moment, not at the moment it was learnt', function () {
        $shipment = parcelOnItsWay($this->branch);
        $yesterdayEvening = now()->subDay()->setTime(17, 40);

        $this->action->handle($shipment, courierSays(ShipmentStatus::InTransit, [
            new TrackingEvent(ShipmentStatus::InTransit, 'Scan sore', $yesterdayEvening),
        ]));

        expect($shipment->trackingEvents()->sole()->event_at->format('H:i'))->toBe('17:40');
    });

    it('keeps a scan that does not move the parcel on the timeline without rewinding it', function () {
        $shipment = parcelOnItsWay($this->branch, ShipmentStatus::Delivered);
        $shipment->update(['delivered_at' => now()->subHour()]);

        $this->action->handle($shipment->fresh(), courierSays(ShipmentStatus::InTransit, [
            new TrackingEvent(ShipmentStatus::InTransit, 'Scan yang datang terlambat', now()->subHours(5)),
        ]));

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Delivered)
            ->and($shipment->fresh()->trackingEvents()->count())->toBe(1);
    });

    it('caps a courier paragraph to what the column holds', function () {
        $shipment = parcelOnItsWay($this->branch);

        $this->action->handle($shipment, courierSays(ShipmentStatus::InTransit, [
            new TrackingEvent(ShipmentStatus::InTransit, str_repeat('a', 400), now()),
        ]));

        expect(mb_strlen($shipment->trackingEvents()->sole()->description))->toBe(255);
    });
});

describe('when the parcel arrives', function () {
    it('stamps the shipment and moves the order to delivered', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->handle($shipment, courierSays(ShipmentStatus::Delivered, [
            new TrackingEvent(ShipmentStatus::Delivered, 'Diterima Budi', now()),
        ]));

        expect($updated->status)->toBe(ShipmentStatus::Delivered)
            ->and($updated->delivered_at)->not->toBeNull()
            ->and($updated->order->fresh()->status)->toBe(OrderStatus::Delivered);
    });

    it('clears a failure the parcel was carrying when it arrives on a second attempt', function () {
        $shipment = parcelOnItsWay($this->branch, ShipmentStatus::Failed);
        $shipment->update(['failure_reason' => 'Penerima tidak di tempat.']);

        $updated = $this->action->handle($shipment->fresh(), courierSays(ShipmentStatus::Delivered, [
            new TrackingEvent(ShipmentStatus::Delivered, 'Diterima Budi', now()),
        ]));

        expect($updated->failure_reason)->toBeNull();
    });

    it('leaves an order somebody else has moved on exactly where it is', function () {
        $order = Order::factory()->forBranch($this->branch)->completed()->create();
        $shipment = Shipment::factory()->forOrder($order)->inTransit()->create();

        $updated = $this->action->handle($shipment, courierSays(ShipmentStatus::Delivered, [
            new TrackingEvent(ShipmentStatus::Delivered, 'Diterima Budi', now()),
        ]));

        // The parcel's own position is still recorded — that is the fact being
        // learnt — while the order is left for a person to reconcile.
        expect($updated->status)->toBe(ShipmentStatus::Delivered)
            ->and($order->fresh()->status)->toBe(OrderStatus::Completed);
    });
});

describe('when the parcel does not arrive', function () {
    it('summarises the failure on the row from what the timeline says', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->handle($shipment, courierSays(ShipmentStatus::Failed, [
            new TrackingEvent(ShipmentStatus::Failed, 'Alamat tidak ditemukan', now()),
        ]));

        expect($updated->status)->toBe(ShipmentStatus::Failed)
            ->and($updated->failure_reason)->toBe('Alamat tidak ditemukan');
    });

    it('leaves the order shipped when the parcel comes back, so the two can disagree', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->handle($shipment, courierSays(ShipmentStatus::Returned, [
            new TrackingEvent(ShipmentStatus::Returned, 'Paket dikembalikan ke pengirim', now()),
        ]));

        expect($updated->status)->toBe(ShipmentStatus::Returned)
            ->and($updated->isOpen())->toBeFalse()
            ->and($updated->order->fresh()->status)->toBe(OrderStatus::Shipped);
    });
});

describe('a status the branch types in', function () {
    it('records the branch own words and attributes them to the branch', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->manual(
            shipment: $shipment,
            status: ShipmentStatus::InTransit,
            description: 'Dijemput kurir, masih di gudang JNE Bandung.',
            location: 'Bandung',
            actor: $this->staff,
        );

        $event = $updated->trackingEvents()->latest('id')->first();

        expect($updated->status)->toBe(ShipmentStatus::InTransit)
            ->and($event->description)->toBe('Dijemput kurir, masih di gudang JNE Bandung.')
            ->and($event->source)->toBe(ShipmentTrackingEvent::SOURCE_BRANCH)
            ->and($event->location)->toBe('Bandung');
    });

    it('falls back to the status label when no words were typed', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->manual($shipment, ShipmentStatus::InTransit, actor: $this->staff);

        expect($updated->trackingEvents()->latest('id')->first()->description)
            ->toBe(ShipmentStatus::InTransit->label());
    });

    it('does not pretend a provider was asked', function () {
        $shipment = parcelOnItsWay($this->branch);

        $updated = $this->action->manual($shipment, ShipmentStatus::InTransit, actor: $this->staff);

        // `tracking_synced_at` records when a provider was last asked. Writing
        // it here would make the sweep skip exactly the parcels somebody is
        // worried enough about to be updating by hand.
        expect($updated->tracking_synced_at)->toBeNull();
    });

    it('moves the order to delivered like a courier scan would', function () {
        $shipment = parcelOnItsWay($this->branch);

        $this->action->manual($shipment, ShipmentStatus::Delivered, actor: $this->staff);

        expect($shipment->order->fresh()->status)->toBe(OrderStatus::Delivered);
    });

    it('refuses a parcel that has not left the branch', function () {
        $order = Order::factory()->forBranch($this->branch)->processing()->create();
        $shipment = Shipment::factory()->forOrder($order)->create();

        expect(fn () => $this->action->manual($shipment, ShipmentStatus::InTransit, actor: $this->staff))
            ->toThrow(ShippingFailedException::class, 'belum diserahkan ke kurir');
    });
});
