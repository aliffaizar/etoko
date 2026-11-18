<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminHost;
use App\Support\NotificationPresenter;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * How many notifications the bell shows before "Lihat semua".
     */
    private const BELL_PREVIEW = 6;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->loadMissing('branch');
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'role' => $user instanceof User ? $user->appRole()?->value : null,
                'roleLabel' => $user instanceof User ? $user->appRole()?->label() : null,
                'isCentral' => $user instanceof User && $user->isCentral(),
                // Whether the account belongs to a management panel, which
                // decides the shell the settings screens are drawn in and
                // whether self-deletion is offered at all (ProfileController).
                'isStaff' => $user instanceof User && $user->isStaff(),
                'branch' => $user instanceof User && $user->branch !== null
                    ? ['id' => $user->branch->id, 'name' => $user->branch->name, 'code' => $user->branch->code]
                    : null,
                'permissions' => $user instanceof User ? $this->permissionsFor($user) : [],
            ],
            // The panel's bell (P2-E6-T4): staff only, and a closure so a
            // partial reload that does not ask for it does not pay for it.
            // Kept apart from any page's own `notifications` prop (the branch
            // dashboard has one) by its name.
            'notificationBell' => $user instanceof User && $user->isStaff() && AdminHost::reachableFrom($request)
                ? fn (): array => $this->notificationBell($user)
                : null,
            // Whether the panels answer on this host (ADR-063). False on the
            // storefront once they have a host of their own, where a staff
            // member's settings screens are drawn in the shop's shell: the
            // panel menu would link to a host the session does not reach.
            'panelsReachable' => AdminHost::reachableFrom($request),
            // The root URL of each host, port included, for the few links that
            // cross from one to the other (`HostLink`). Null on a single host,
            // where every link is a plain path.
            'hostRoots' => AdminHost::isSeparate()
                ? ['storefront' => AdminHost::storefrontRootUrl(), 'panel' => AdminHost::rootUrl()]
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * How many notifications are unread, and the latest few to show.
     *
     * @return array{unread: int, recent: array<int, array<string, mixed>>}
     */
    protected function notificationBell(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'recent' => $user->notifications()
                ->limit(self::BELL_PREVIEW)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => NotificationPresenter::item($notification, $user))
                ->all(),
        ];
    }

    /**
     * The permission names the user holds, used to build the admin menu.
     *
     * The menu is a convenience only: every route is still guarded server side
     * by its own permission and policy check.
     *
     * @return array<int, string>
     */
    protected function permissionsFor(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->values()->all();
    }
}
