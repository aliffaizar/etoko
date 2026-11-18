<?php

namespace App\Http\Middleware;

use App\Support\AdminHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides sign-up on the panel host (ADR-063, P2-E9-T7).
 *
 * Registering makes a customer account, and customers may not sign in on the
 * panel host, so the screen there would only create an account that is
 * refused at the next login. Fortify registers the routes itself and offers
 * no way to bind them to a host, so they are refused here instead, by name.
 */
class RefuseRegistrationOnAdminHost
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(AdminHost::serves($request) && $request->routeIs('register', 'register.store'), 404);

        return $next($request);
    }
}
