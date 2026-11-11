<?php

namespace App\Actions\Shipping;

use App\Exceptions\ShippingFailedException;
use App\Models\Courier;
use App\Models\CourierService;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Services\Shipping\ShippingManager;
use Illuminate\Support\Facades\Log;

/**
 * What it costs to send this parcel, every way the shop can send it
 * (P2-E2-T2).
 *
 * Asked in three places and it has to answer the same in all three, which is
 * the whole reason it is an action rather than a method on a controller: the
 * checkout screen asks it to draw the options, `PlaceOrder` asks it again to
 * price the option the customer picked, and the dispatch form asks it to price
 * the one the packer actually used. A customer shown one figure and charged
 * another is the failure this prevents.
 *
 * **The client's number is never trusted** (ADR-042). The checkout form sends back a
 * `courier_service_id` and nothing else; `forService()` re-quotes it against
 * the address and the weight the order is really made of. Accepting a cost
 * from the browser would let anybody ship a sofa for a rupiah.
 *
 * A courier whose provider is down is **skipped**, not fatal: a shop with
 * three couriers should still sell when one aggregator is unreachable. But a
 * quote that comes back empty **because** every provider failed is an outage
 * rather than an unserved address, and it says so — otherwise an API outage
 * would read to every customer as "we do not deliver to you", and they would
 * believe it.
 */
final class QuoteShipping
{
    public function __construct(private readonly ShippingManager $manager) {}

    /**
     * Every option for this parcel, cheapest first.
     *
     * Cheapest first because that is the order a shopper compares them in, and
     * ties break on the faster promise — two couriers at the same price are
     * distinguished by when they land, which is the only thing left to choose
     * on. A service that promises nothing sorts last within its price, because
     * "no estimate" is weaker than any estimate.
     *
     * @return array<int, ShippingQuote>
     *
     * @throws ShippingFailedException when every provider refused or could not be reached.
     */
    public function handle(ShippingQuoteRequest $request): array
    {
        if (! $request->hasDestination()) {
            return [];
        }

        $couriers = Courier::query()->active()->ordered()->get();
        $quotes = [];
        $failures = 0;

        foreach ($couriers as $courier) {
            try {
                $quotes = [...$quotes, ...$this->manager->for($courier)->quote($courier, $request)];
            } catch (ShippingFailedException $refused) {
                $failures++;

                // Logged rather than surfaced: the shopper is looking at a
                // list of options, and one courier's outage is not a sentence
                // they can act on. The log line is what makes "why did JNE
                // stop appearing on Tuesday?" answerable.
                Log::warning('A courier could not be quoted.', [
                    'courier' => $courier->code,
                    'driver' => $courier->driver->value,
                    'reason' => $refused->reason,
                    'message' => $refused->getMessage(),
                ]);
            }
        }

        if ($quotes === [] && $failures > 0) {
            throw ShippingFailedException::providerUnreachable();
        }

        usort($quotes, function (ShippingQuote $a, ShippingQuote $b): int {
            return [$a->cost, $a->etdMaxDay ?? PHP_INT_MAX, $a->displayName()]
                <=> [$b->cost, $b->etdMaxDay ?? PHP_INT_MAX, $b->displayName()];
        });

        return $quotes;
    }

    /**
     * The quote for one service, priced against this parcel.
     *
     * The authoritative call: `PlaceOrder` and `DispatchShipment` use it, so
     * the cost written onto an order is one this application worked out from
     * its own rate table and the order's own weight.
     *
     * Refuses rather than returning null, because every caller is at the point
     * of writing money onto a row and has a sentence to show somebody. The two
     * refusals are deliberately different: a service switched off since the
     * screen was drawn is the shop's own doing and names the service, while a
     * service with no rule reaching this address is about the address.
     *
     * @throws ShippingFailedException
     */
    public function forService(CourierService $service, ShippingQuoteRequest $request): ShippingQuote
    {
        if (! $request->hasDestination()) {
            throw ShippingFailedException::destinationMissing();
        }

        $service->loadMissing('courier');
        $courier = $service->courier;

        if (! $courier->is_active || ! $service->is_active) {
            throw ShippingFailedException::serviceUnavailable($service);
        }

        $quote = collect($this->manager->for($courier)->quote($courier, $request))
            ->first(fn (ShippingQuote $candidate): bool => $candidate->courierServiceId === $service->getKey());

        if (! $quote instanceof ShippingQuote) {
            throw ShippingFailedException::noServiceAvailable($request);
        }

        return $quote;
    }
}
