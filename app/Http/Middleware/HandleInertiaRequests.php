<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
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
