<?php

use App\Actions\Shipping\RecordShipmentTracking;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingFailedException;
use App\Jobs\SyncShipmentTracking;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingEvent;
use App\Services\Shipping\Data\TrackingResult;
use App\Services\Shipping\Drivers\BiteshipProvider;
use App\Services\Shipping\ShippingManager;
use App\Services\Shipping\ShippingProvider;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Asking couriers where their parcels are (P2-E2-T6).
 *
 * Without this sweep a shipment's status is only ever what the branch last
 * typed, so a customer watching their order sees one update and then nothing.
 *
 * Two properties matter more than the happy path: the sweep asks **only**
 * about parcels somebody can answer for, and one unreachable courier does not
 * stop the other thirty parcels being updated.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->courier = Courier::factory()->biteship()->create(['name' => 'JNE']);
    $this->service = CourierService::factory()->forCourier($this->courier)->create();
});

/**
 * A parcel on its way with a courier an aggregator can answer about.
 */
function trackableParcel(Branch $branch, CourierService $service, string $waybill = 'JNE0099887766'): Shipment
{
    $order = Order::factory()->forBranch($branch)->status(OrderStatus::Shipped)->create();

    return Shipment::factory()->forOrder($order)->forService($service)->inTransit($waybill)->create();
}

/**
 * Run one sweep, the way the scheduler does.
 */
function sweepTracking(): void
{
    app(SyncShipmentTracking::class)->handle(
        app(ShippingManager::class),
        app(RecordShipmentTracking::class),
    );
}

/**
 * A provider that answers with one position for every parcel it is asked
 * about, and records which parcels those were.
 */
function providerAnswering(ShipmentStatus $status, ?string $refuseWaybill = null): ShippingProvider
{
    return new class($status, $refuseWaybill) implements ShippingProvider
    {
        /** @var array<int, string> */
        public array $asked = [];

        public function __construct(
            private readonly ShipmentStatus $status,
            private readonly ?string $refuseWaybill,
        ) {}

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
            $this->asked[] = (string) $shipment->tracking_number;

            if ($shipment->tracking_number === $this->refuseWaybill) {
                throw ShippingFailedException::providerUnreachable();
            }

            return new TrackingResult(
                status: $this->status,
                events: [new TrackingEvent($this->status, $this->status->label(), now())],
            );
        }
    };
}

it('applies what the courier reports to every parcel it can ask about', function () {
    $first = trackableParcel($this->branch, $this->service, 'JNE111');
    $second = trackableParcel($this->branch, $this->service, 'JNE222');

    app()->instance(BiteshipProvider::class, $provider = providerAnswering(ShipmentStatus::Delivered));

    sweepTracking();

    expect($provider->asked)->toHaveCount(2)
        ->and($first->fresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($second->fresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($first->fresh()->order->status)->toBe(OrderStatus::Delivered);
});

it('asks nobody about a parcel its courier is managed by hand', function () {
    $manual = Courier::factory()->create();
    $manualService = CourierService::factory()->forCourier($manual)->create();
    trackableParcel($this->branch, $manualService, 'MANUAL1');

    app()->instance(BiteshipProvider::class, $provider = providerAnswering(ShipmentStatus::Delivered));

    sweepTracking();

    expect($provider->asked)->toBe([]);
});

it('asks nobody about a parcel that is finished, waiting at the branch, or has no waybill', function () {
    $order = Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create();

    Shipment::factory()->forOrder($order)->forService($this->service)->delivered('DONE1')->create();
    Shipment::factory()->forOrder(
        Order::factory()->forBranch($this->branch)->processing()->create()
    )->forService($this->service)->create();
    Shipment::factory()->forOrder(
        Order::factory()->forBranch($this->branch)->status(OrderStatus::Shipped)->create()
    )->forService($this->service)->create(['status' => ShipmentStatus::PickedUp, 'tracking_number' => null]);

    app()->instance(BiteshipProvider::class, $provider = providerAnswering(ShipmentStatus::Delivered));

    sweepTracking();

    expect($provider->asked)->toBe([]);
});

it('keeps going when one courier cannot be reached', function () {
    $unreachable = trackableParcel($this->branch, $this->service, 'DOWN1');
    $fine = trackableParcel($this->branch, $this->service, 'JNE222');

    app()->instance(BiteshipProvider::class, providerAnswering(ShipmentStatus::Delivered, refuseWaybill: 'DOWN1'));

    sweepTracking();

    expect($fine->fresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($unreachable->fresh()->status)->toBe(ShipmentStatus::InTransit)
        // "We could not ask" is not an answer: leaving the column alone is
        // what makes the next run try this parcel again.
        ->and($unreachable->fresh()->tracking_synced_at)->toBeNull();
});

it('reads parcels across every branch, since a queue worker stands in none', function () {
    $other = Branch::factory()->create();
    $elsewhere = trackableParcel($other, $this->service, 'JNE333');

    app()->instance(BiteshipProvider::class, providerAnswering(ShipmentStatus::Delivered));

    sweepTracking();

    expect($elsewhere->fresh()->status)->toBe(ShipmentStatus::Delivered);
});

it('is scheduled every ten minutes without overlapping itself', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'sync-shipment-tracking');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
