<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

/**
 * Sends each role to the area it works in after a passkey sign-in.
 *
 * The passkey flow is an XHR call: the login screen reads `redirect` from the
 * JSON body and visits it, so the role's landing page has to be resolved here
 * as well (the package default points at the generic dashboard).
 */
class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        $target = RoleHome::intendedUrlFor($request);

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => url($target)], 200);
        }

        return redirect()->to($target);
    }
}
