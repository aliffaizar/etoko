<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * Sends a freshly registered account to its role's landing page.
 *
 * Public registration always produces a customer (`CreateNewUser`), so this is
 * the storefront rather than the generic dashboard.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->to(RoleHome::intendedUrlFor($request));
    }
}
