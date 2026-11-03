<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The generic dashboard, which only accounts without an application role see.
 *
 * Anyone holding a role is forwarded to the panel that role works in, so an old
 * bookmark or a link in the starter-kit header cannot strand a user on a page
 * that has nothing for them (P1-E1-T7). Comparing paths keeps the role whose
 * landing page resolves back to here from looping.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $home = RoleHome::urlFor($user instanceof User ? $user : null);

        if (parse_url($home, PHP_URL_PATH) !== $request->getPathInfo()) {
            return redirect()->to($home);
        }

        return Inertia::render('dashboard');
    }
}
