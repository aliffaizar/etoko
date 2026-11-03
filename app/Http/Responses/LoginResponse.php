<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        return redirect()->to(RoleHome::intendedUrlFor($request));
    }
}
