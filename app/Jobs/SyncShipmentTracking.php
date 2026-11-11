<?php

namespace App\Jobs;

use App\Actions\Shipping\RecordShipmentTracking;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\ShippingFailedException;
use App\Models\Shipment;
use App\Scopes\BranchScope;
use App\Services\Shipping\ShippingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Asks couriers where their parcels are (P2-E2-T6).
 *
 * Without this, a shipment's status is only ever what the branch last typed —
 * which means a customer watching their order sees "Dalam Perjalanan" for four
 * days and then nothing, because nobody at the branch is going to log in and
 * mark three hundred parcels delivered.
 *
 * Runs every ten minutes from `routes/console.php`, not every minute like
 * `ExpireStockReservations`. The difference is what is at stake: an expired
 * stock hold keeps goods off the shelf, so a minute of lag costs sales, while a
 * tracking scan that arrives ten minutes late costs nothing at all — and every
 * run is somebody else's rate limit being spent.
 *
 * **Only parcels that can be asked about**, which is three conditions and each
 * one is a real case (`Shipment::isTrackable()`): the parcel is still moving, it
 * has a waybill, and its courier is served by a driver that can answer. A
 * manual courier is excluded by the last one — there is nobody to ask, and the
 * branch's own entries are the timeline.
 *
 * **One courier's outage must not stop the others.** Each shipment is tracked
 * inside its own try/catch: a `ShippingFailedException` is logged against that
 * parcel and the sweep moves on, because the alternative is a single
 * unreachable provider freezing every customer's tracking page. `tracking_synced_at`
 * is left untouched on a failure, so the next run tries it again rather than
 * treating "we could not ask" as an answer.
 *
 * `WithoutOverlapping` keeps two runs apart. `RecordShipmentTracking` is safe
 * to run twice — the unique index on the timeline makes a repeated scan a
 * no-op, and the status only moves forward — but two sweeps racing would spend
 * their time losing to each other's locks and double every provider call.
 */
class SyncShipmentTracking implements ShouldQueue
{
    use Queueable;

    /**
     * How many parcels one run will ask about.
     *
     * A ceiling rather than "all of them", because this runs on a schedule and
     * a backlog of a thousand parcels would make one run a thousand HTTP
     * requests holding the queue worker for an hour. The oldest-synced go
     * first, so a backlog drains in order instead of the same parcels being
     * asked about every time.
     */
    private const BATCH_SIZE = 100;

    public function handle(ShippingManager $manager, RecordShipmentTracking $record): void
    {
        $tracked = 0;
        $failed = 0;

        foreach ($this->shipments() as $shipment) {
            if (! $shipment->isTrackable()) {
                continue;
            }

            try {
                $record->handle($shipment, $manager->forShipment($shipment)->track($shipment));
                $tracked++;
            } catch (ShippingFailedException $unavailable) {
                $failed++;

                Log::warning('A parcel could not be tracked.', [
                    'shipment_id' => $shipment->getKey(),
                    'courier' => $shipment->courier_name,
                    'tracking_number' => $shipment->tracking_number,
                    'reason' => $unavailable->reason,
                    'message' => $unavailable->getMessage(),
                ]);
            } catch (IllegalOrderTransitionException $moved) {
                $failed++;

                // The parcel arrived at an order that had already been
                // cancelled or completed by hand. Logged loudly rather than
                // retried: it needs a person to reconcile, and the scan itself
                // was rolled back with the transition.
                Log::warning('A delivered parcel could not move its order.', [
                    'shipment_id' => $shipment->getKey(),
                    'message' => $moved->getMessage(),
                ]);
            }
        }

        if ($tracked > 0 || $failed > 0) {
            Log::info('Shipment tracking sweep finished.', ['tracked' => $tracked, 'failed' => $failed]);
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-shipment-tracking'))->dontRelease()];
    }

    /**
     * The parcels this run will ask about.
     *
     * One parcel on demand is **not** this job's business: the panel's
     * "refresh tracking" button calls the provider in the foreground through
     * `ShipmentController::refreshTracking()`, because the staff member
     * pressing it is waiting for an answer and this job's whole design is to
     * swallow a provider's refusal into a log line.
     *
     * Read **across branches**: a scheduled sweep has no branch context, and
     * narrowing it to one would silently mean only the parcels of whichever
     * branch happened to be set — which, for a queue worker, is none.
     *
     * The courier is eager-loaded because `isTrackable()` and
     * `ShippingManager::forShipment()` both read its driver, and a lazy load
     * there is one query per parcel — which `preventLazyLoading` turns into an
     * exception in development anyway.
     *
     * @return Collection<int, Shipment>
     */
    private function shipments()
    {
        return Shipment::withoutGlobalScope(BranchScope::class)
            ->with(['courier', 'order'])
            ->awaitingTracking()
            // Never-asked parcels first, then the longest since anybody asked,
            // so a backlog drains in order instead of the same parcels being
            // asked about on every run.
            ->orderByRaw('tracking_synced_at is null desc')
            ->orderBy('tracking_synced_at')
            ->limit(self::BATCH_SIZE)
            ->get();
    }
}
