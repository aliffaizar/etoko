<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the page a signed-in user belongs on (P1-E1-T7).
 *
 * Every way into the application after authenticating — password login, the
 * two-factor challenge, passkey login, registration and email verification —
 * goes through here, so each role has exactly one landing page (ADR-015).
 */
final class RoleHome
{
    /**
     * The landing page for the user's role.
     *
     * Falls back through the panel the role belongs to, so a role whose own
     * landing page does not exist yet (POS, for example) still lands somewhere
     * useful instead of on the generic dashboard.
     *
     * Host aware (ADR-063): on a host the panels are not served from, staff
     * belong on the storefront. Sending them to the panel host would only land
     * them on its sign-in screen, since the session does not cross hosts.
     */
    public static function urlFor(?User $user, ?Request $request = null): string
    {
        $role = $user?->appRole();

        if ($role === null) {
            return config('fortify.home');
        }

        if (! AdminHost::reachableFrom($request ?? request())) {
            return route('home');
        }

        $candidates = [$role->homeRouteName()];

        if ($role->isCentral()) {
            $candidates[] = 'admin.dashboard';
        } elseif ($role->requiresBranch()) {
            $candidates[] = 'branch.dashboard';
        }

        foreach ($candidates as $routeName) {
            if (Route::has($routeName)) {
                return route($routeName);
            }
        }

        return config('fortify.home');
    }

    /**
     * Where to send the user now, consuming the page they were headed for.
     *
     * Laravel parks the blocked URL in the session when a guest is bounced to
     * the login screen. Honouring it blindly drops a cashier who followed an
     * `/admin` link onto a 403 page, so it only wins when it belongs to an area
     * the role may enter — otherwise the role's own landing page does.
     */
    public static function intendedUrlFor(Request $request): string
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || trim($intended) === '') {
            return self::urlFor($user, $request);
        }

        return self::mayEnter($request, $user, $intended)
            ? $intended
            : self::urlFor($user, $request);
    }

    /**
     * Whether the user is allowed into the area the given URL lives in.
     */
    private static function mayEnter(Request $request, ?User $user, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host !== null && $host !== $request->getHost()) {
            return false;
        }

        $role = $user?->appRole();

        return $role === null || $role->mayEnter((string) parse_url($url, PHP_URL_PATH));
    }
}
