<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Sends each role to the area it works in after signing in (P1-E1-T7).
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended($this->homeFor($request));
    }

    /**
     * The landing page for the user's role.
     *
     * Falls back through the panel the role belongs to, so a role whose own
     * landing page does not exist yet (POS, for example) still lands somewhere
     * useful instead of on the generic dashboard.
     */
    private function homeFor(Request $request): string
    {
        $user = $request->user();
        $role = $user instanceof User ? $user->appRole() : null;

        if ($role === null) {
            return config('fortify.home');
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
}
