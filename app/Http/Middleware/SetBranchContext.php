<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Branch\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the request's data access to the signed-in user's branch (BR-3).
 *
 * Central staff, customers and guests are left unrestricted; the branch global
 * scope then adds nothing to their queries.
 */
class SetBranchContext
{
    public function __construct(private BranchContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isBranchBound() && $user->branch_id !== null) {
            $this->context->restrictTo($user->branch_id);
        }

        return $next($request);
    }
}
