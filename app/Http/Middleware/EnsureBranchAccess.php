<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the management panels.
 *
 * Central staff may enter to supervise any branch, but a branch-bound user
 * without an assigned branch is refused rather than silently shown nothing.
 *
 * It sits in front of `/branch/*` and `/admin/*` alike. A branch-bound account
 * with no `branch_id` is a state the forms prevent, but if one ever reached the
 * central panel the BR-3 narrowing there would have nothing to narrow by, so
 * the state is refused at the door instead of being relied on not to happen.
 */
class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isStaff(), 403);

        if ($user->isCentral()) {
            return $next($request);
        }

        abort_if($user->branch_id === null, 403, 'Akun Anda belum ditugaskan ke cabang mana pun.');

        return $next($request);
    }
}
