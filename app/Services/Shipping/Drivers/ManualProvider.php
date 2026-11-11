<?php

namespace App\Services\Shipping\Drivers;

use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Services\Shipping\Data\ShipmentResult;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\Data\TrackingResult;
use App\Services\Shipping\ShippingProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Shipping the shop arranges itself (P2-E2-T2, ADR-004).
 *
 * The driver Phase 1–2 runs on: prices come out of `shipping_rates`, the
 * waybill number is whatever the branch brings back from the courier's counter,
 * and the timeline is what the branch types. No network calls, no API keys,
 * and nothing that can be down.
 *
 * **Rate resolution is "most specific rule wins", and the ordering is the
 * design.** For each service, every active rule that could apply is collected
 * — the ones written for this origin branch and the ones written for any
 * origin — then ranked:
 *
 * 1. by **destination specificity** (district beats city beats province beats
 *    the catch-all), because a rule naming one district was written to
 *    override the province rule it sits inside;
 * 2. then by **a named origin over a null one**, because a branch that keeps
 *    its own price list keeps it for a reason;
 * 3. then by the **cheaper price**, so two rules that are equally specific and
 *    disagree resolve in the customer's favour rather than by insertion order.
 *
 * Without an ordering, "which rate applies?" would depend on which row was
 * saved first — a question nobody can answer by looking at the table.
 *
 * Destinations are matched by **name**, not by region id (ADR-043): `addresses`
 * stores what the customer typed and its region foreign keys are never
 * populated, so a lookup keyed on ids would match nothing and silently charge
 * every order the catch-all rate.
 *
 * A service with no matching rule is **left out of the quote** rather than
 * quoted at zero. Free shipping and "we do not go there" are different
 * answers, and a table whose gaps read as free is a table that loses money
 * quietly.
 */
final class ManualProvider implements ShippingProvider
{
    /**
     * @return array<int, ShippingQuote>
     */
    public function quote(Courier $courier, ShippingQuoteRequest $request): array
    {
        if (! $request->hasDestination()) {
            return [];
        }

        $services = $this->activeServices($courier);

        if ($services->isEmpty()) {
            return [];
        }

        $rates = $this->candidateRates($services->modelKeys(), $request);
        $quotes = [];

        foreach ($services as $service) {
            $rate = $this->bestRateFor($rates->get($service->getKey(), new Collection), $request);

            if (! $rate instanceof ShippingRate) {
                continue;
            }

            $quotes[] = ShippingQuote::forService(
                service: $service,
                cost: $rate->priceFor($request->weightGram),
            );
        }

        return $quotes;
    }

    /**
     * There is nobody to book with: the branch goes to the counter.
     *
     * The shipment is read rather than ignored so a waybill number already
     * typed in survives — `DispatchShipment` calls this before it writes, and
     * a result that blanked the number the staff member had just entered would
     * be a driver undoing the form.
     */
    public function createShipment(Shipment $shipment): ShipmentResult
    {
        return ShipmentResult::manual($shipment->tracking_number);
    }

    /**
     * Nobody to ask.
     *
     * A manual courier's timeline is what the branch typed, so this refuses
     * rather than inventing a position. `Shipment::isTrackable()` keeps the
     * sweep from ever getting here; this is the second line, for a courier
     * whose driver was changed after its parcels went out.
     */
    public function track(Shipment $shipment): TrackingResult
    {
        throw ShippingFailedException::unsupported(
            "Kurir \"{$shipment->courier_name}\" dikelola manual, jadi posisi paketnya tidak dapat ditanyakan otomatis. "
                .'Perbarui statusnya dari kartu pengiriman.',
        );
    }

    /**
     * The courier's services that are on offer, with the courier loaded.
     *
     * The courier is attached from the row already in hand rather than
     * re-queried: `ShippingQuote::forService()` reads it for every quote, and
     * a lazy load there would be one query per service — which
     * `preventLazyLoading` would turn into an exception in development anyway.
     *
     * @return Collection<int, CourierService>
     */
    private function activeServices(Courier $courier): Collection
    {
        /** @var Collection<int, CourierService> $services */
        $services = $courier->services()->active()->ordered()->get();

        $services->each(fn (CourierService $service) => $service->setRelation('courier', $courier));

        return $services;
    }

    /**
     * Every rule that could price one of these services, grouped by service.
     *
     * One query for the whole quote, because quoting runs once per courier on
     * a checkout screen and a query per service would make the screen's cost
     * depend on how many tiers the shop sells.
     *
     * Rules with a null `courier_service_id` are included **for every
     * service**: null means "any service of any courier", which is how a shop
     * sets one floor in one row. They are grouped under each service id rather
     * than under null so `bestRateFor()` ranks them against the specific ones.
     *
     * @param  array<int, int|string>  $serviceIds
     * @return BaseCollection<int, Collection<int, ShippingRate>>
     */
    private function candidateRates(array $serviceIds, ShippingQuoteRequest $request): BaseCollection
    {
        $rates = ShippingRate::query()
            ->active()
            ->shippableFrom($request->origin->getKey())
            ->where(fn ($query) => $query
                ->whereIn('courier_service_id', $serviceIds)
                ->orWhereNull('courier_service_id'))
            ->get();

        [$wildcards, $specific] = $rates->partition(
            fn (ShippingRate $rate): bool => $rate->courier_service_id === null,
        );

        /** @var BaseCollection<int, Collection<int, ShippingRate>> $grouped */
        $grouped = $specific->groupBy('courier_service_id');

        if ($wildcards->isEmpty()) {
            return $grouped;
        }

        foreach ($serviceIds as $serviceId) {
            $grouped->put(
                (int) $serviceId,
                $grouped->get((int) $serviceId, new Collection)->merge($wildcards),
            );
        }

        return $grouped;
    }

    /**
     * The one rule that prices this parcel, or null when no rule reaches it.
     *
     * @param  Collection<int, ShippingRate>  $rates
     */
    private function bestRateFor(Collection $rates, ShippingQuoteRequest $request): ?ShippingRate
    {
        $destination = $request->destinationNames();
        $originId = $request->origin->getKey();

        return $rates
            ->filter(fn (ShippingRate $rate): bool => $rate->coversDestination($destination))
            ->sort(function (ShippingRate $a, ShippingRate $b) use ($request, $originId): int {
                return $this->rank($b, $originId, $request) <=> $this->rank($a, $originId, $request);
            })
            ->first();
    }

    /**
     * How strong a rule's claim on this parcel is; higher wins.
     *
     * Packed into one integer so the comparison is a single `<=>`: the
     * destination's specificity dominates, a named origin breaks a tie within
     * it, and the cheaper price breaks what is left. The price term is
     * subtracted and clamped so a very expensive rule can never bleed into the
     * specificity above it.
     */
    private function rank(ShippingRate $rate, int $originId, ShippingQuoteRequest $request): int
    {
        $specificity = $rate->destination_type->specificity();
        $ownOrigin = $rate->origin_branch_id === $originId ? 1 : 0;
        $cheapness = max(0, 10_000_000 - $rate->priceFor($request->weightGram));

        return ($specificity * 100_000_000) + ($ownOrigin * 20_000_000) + $cheapness;
    }
}
