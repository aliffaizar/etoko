<?php

use App\Jobs\ExpireStockReservations;
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
