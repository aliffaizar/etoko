<?php

namespace App\Http\Responses;

use App\Support\RoleHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * Sends a user who just verified their email to their role's landing page.
 *
 * The `verified=1` marker Fortify appends is kept, so the destination page can
 * still greet the user.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $target = RoleHome::intendedUrlFor($request);

        return redirect()->to($target.(Str::contains($target, '?') ? '&' : '?').'verified=1');
    }
}
