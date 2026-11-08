<?php

use App\Http\Controllers\Webhook\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Endpoints somebody else's server calls. They are **not** in the `web` group:
| there is no session, no CSRF token and no authentication, because the caller
| is a payment provider and has none of those to offer (P2-E1-T6).
|
| What replaces them is the driver's own signature check, run before a single
| row is read — see `Webhook\PaymentWebhookController` and
| `MidtransGateway::handleCallback()`. A payload that cannot be verified is
| refused with a 400 so the gateway stops retrying it.
|
| Rate limited, because this is a public endpoint and an unthrottled one is a
| way to make the application do database work for free. The limit is
| deliberately generous — a gateway settling a busy afternoon's orders sends a
| genuine burst, and dropping a real settlement is far worse than serving a few
| forged ones that the signature check will refuse anyway.
|
| `manual` is a value `PaymentDriver` allows and this route will always refuse,
| which is correct rather than an oversight: nobody calls a shop's webhook to
| say a bank transfer landed, and `ManualGateway::handleCallback()` says so.
|
*/

Route::prefix('webhook')
    ->name('webhook.')
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::post('payment/{driver}', PaymentWebhookController::class)
            ->whereIn('driver', ['manual', 'midtrans', 'xendit'])
            ->name('payment');
    });
