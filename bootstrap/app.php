<?php

use App\Http\Middleware\EnsureBranchAccess;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Webhooks are registered **outside** the `web` group (P2-E1-T6).
         *
         * The caller is a payment provider's server: it has no session, no
         * CSRF token and no cookies to encrypt, and running the Inertia and
         * branch-context middleware for it would be work done for a machine
         * that reads nothing but the status code. What takes the place of
         * those guards is the driver's signature check — see
         * `Webhook\PaymentWebhookController`.
         *
         * This is the one deviation from docs/06-conventions.md §Route, which
         * has every area's route file required from `web.php`; requiring this
         * one there would put it back inside the group it has to stay out of.
         */
        then: function (): void {
            Route::group([], base_path('routes/webhook.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
         * `SubstituteBindings` is taken out of its default position and put
         * back after `SetBranchContext` on purpose.
         *
         * Route-model binding is where BR-3 has to bite: a URL naming another
         * branch's stock document, order or warehouse must resolve to nothing
         * and answer 404. By default bindings are substituted before the
         * branch context exists, so the global scope is not yet armed and the
         * model is found — leaving only the policy to refuse it with a 403,
         * which tells the caller the record exists.
         *
         * Moving the context in front of the substitution makes the narrowing
         * apply to every branch-scoped model bound by route, in this epic and
         * in the ones that follow.
         */
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                HandleAppearance::class,
                SetBranchContext::class,
                SubstituteBindings::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );

        $middleware->alias([
            'branch' => EnsureBranchAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
