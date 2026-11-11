<?php

use App\Jobs\ExpireStockReservations;
use App\Jobs\SyncShipmentTracking;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Every minute, because the thing being swept is stock that is off the shelf
| and unpayable-for: a hold whose window has closed keeps units from being
| sold to anyone, so the gap between "it expired" and "it is back on sale"
| should be a minute, not an hour (P1-E7-T3, BR-6).
|
| `withoutOverlapping` here and `WithoutOverlapping` on the job itself guard
| two different things: this one stops the scheduler queueing a second run
| while the first is still being dispatched, the job's own middleware stops
| two runs executing at once on the queue.
|
*/

Schedule::job(new ExpireStockReservations)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('expire-stock-reservations');

/*
 * Every ten minutes, not every minute, and the difference is what is at stake
 * (P2-E2-T6). An expired stock hold keeps goods off the shelf, so a minute of
 * lag there costs sales; a courier scan that lands ten minutes late costs
 * nothing, and every run spends somebody else's rate limit.
 *
 * Only parcels a provider can answer about are asked (`Shipment::isTrackable()`),
 * so a shop running entirely on the manual driver does no work here at all.
 */
Schedule::job(new SyncShipmentTracking)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('sync-shipment-tracking');
