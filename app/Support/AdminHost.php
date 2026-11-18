<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where the staff panels live relative to the storefront (ADR-063, P2-E9-T7).
 *
 * With `app.admin_domain` set, `/admin/*` and `/branch/*` are served on that
 * host only and the storefront on the host of `app.url` only; sign-in, the
 * settings screens and the password flows answer on both. Unset, every route
 * shares one host as before, and each method here reports that.
 */
final class AdminHost
{
    /**
     * The panel host, or null when the panels share the storefront's host.
     */
    public static function domain(): ?string
    {
        $domain = config('app.admin_domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * Whether the panels are split off onto a host of their own.
     */
    public static function isSeparate(): bool
    {
        return self::domain() !== null;
    }

    /**
     * The host the storefront routes are bound to, or null when unbound.
     *
     * Only bound once the panels have moved away; until then the storefront
     * answers on any host, as it always has.
     */
    public static function storefrontDomain(): ?string
    {
        if (! self::isSeparate()) {
            return null;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The domain the panel routes are registered under.
     *
     * Null while Wayfinder generates the TypeScript route helpers. Wayfinder
     * writes a route with a domain as `//host/path` and drops the port, which
     * would break every panel link on `admin.localhost:8000`. Without the
     * domain each helper is a plain path, which is right wherever it is used:
     * a panel page links to panel routes on its own host, and the few links
     * that do cross hosts take their root from the shared `hostRoots` prop
     * (`HostLink`).
     */
    public static function panelRouteDomain(): ?string
    {
        return self::generatingRouteHelpers() ? null : self::domain();
    }

    /**
     * The domain the storefront routes are registered under; see
     * `panelRouteDomain()` for why Wayfinder sees none.
     */
    public static function storefrontRouteDomain(): ?string
    {
        return self::generatingRouteHelpers() ? null : self::storefrontDomain();
    }

    /**
     * The storefront's root URL (`app.url`), or null on a single host.
     */
    public static function storefrontRootUrl(): ?string
    {
        return self::isSeparate() ? rtrim((string) config('app.url'), '/') : null;
    }

    /**
     * Whether the request arrived on the panel host.
     */
    public static function serves(Request $request): bool
    {
        $domain = self::domain();

        return $domain !== null && strcasecmp($request->getHost(), $domain) === 0;
    }

    /**
     * Whether the panels can be reached from the host of this request.
     *
     * True on the panel host, and on every host while there is only one.
     */
    public static function reachableFrom(Request $request): bool
    {
        return ! self::isSeparate() || self::serves($request);
    }

    /**
     * The panel host's root URL, carrying the scheme and port of `app.url`.
     */
    public static function rootUrl(): ?string
    {
        $domain = self::domain();

        if ($domain === null) {
            return null;
        }

        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$domain.($port !== null ? ':'.$port : '');
    }

    private static function generatingRouteHelpers(): bool
    {
        return app()->runningConsoleCommand('wayfinder:generate');
    }
}
