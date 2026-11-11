<?php

namespace App\Services\Shipping;

use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingResult;

/**
 * How goods get to a customer, whoever carries them (P2-E2-T2, ADR-004).
 *
 * Three methods, one for each thing that can happen to a parcel, and nothing
 * about screens or routes: an implementation is given a courier and a request
 * and answers with value objects. That is the whole seam — `QuoteShipping`,
 * `DispatchShipment`, `SyncShipmentTracking` and every screen above them are
 * written against this interface, which is what makes `P2-E2-T6` a matter of
 * one `match` arm and one class.
 *
 * Implementations must hold to four rules, because the actions above rely on
 * them:
 *
 * 1. **No writes.** A provider reads the rows it is given and talks to the
 *    outside world; persisting the result is the action's job, inside its own
 *    transaction. A driver that saved its own row would make a failed
 *    transaction a half-finished one — the rule `PaymentGateway` set.
 * 2. **Every method throws rather than returning a failure.** All three are
 *    foreground requests somebody is waiting on: a shopper at checkout, a
 *    packer at the counter, a sweep writing to a log. The failure is a
 *    `ShippingFailedException` carrying one sentence for whoever reads it.
 *    `SyncShipmentTracking` catches it per shipment, so one unreachable
 *    courier does not stop the other thirty parcels being updated.
 * 3. **`quote()` answers only for services the shop has rows for.** The
 *    identity a quote travels back as is a local `courier_services.id`,
 *    because a customer's choice has to be snapshot onto a shipment and
 *    tracked later. A provider offering a service the shop has not configured
 *    is dropped, not invented.
 * 4. **`quote()` returns an empty array rather than throwing when nobody
 *    serves the destination.** "No courier goes there" is an ordinary answer
 *    that the checkout screen turns into "collect at the branch", while a
 *    thrown exception would make an unserved address look like an outage.
 *    `QuoteShipping` is the one that decides whether empty is a refusal.
 */
interface ShippingProvider
{
    /**
     * What this courier would charge to carry the parcel, service by service.
     *
     * @return array<int, ShippingQuote>
     *
     * @throws ShippingFailedException when the provider refuses or cannot be reached.
     */
    public function quote(Courier $courier, ShippingQuoteRequest $request): array;

    /**
     * Hand the parcel over, and come back with a waybill number.
     *
     * @throws ShippingFailedException when the provider refuses or cannot be reached.
     */
    public function createShipment(Shipment $shipment): ShipmentResult;

    /**
     * Ask where the parcel is.
     *
     * The shipment is passed whole rather than just its waybill number,
     * because providers need more than the number: some key on the courier
     * too, some on their own booking id.
     *
     * @throws ShippingFailedException when the provider cannot answer.
     */
    public function track(Shipment $shipment): TrackingResult;
}
