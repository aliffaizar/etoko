<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Sends each role to the area it works in after the two-factor challenge.
 *
 * Fortify's own response lands on the generic dashboard, which would undo the
 * role-based redirect for every account that has two-factor enabled.
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->to(RoleHome::intendedUrlFor($request));
    }
}
