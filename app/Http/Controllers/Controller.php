<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Every controller may call `$this->authorize()`.
 *
 * Authorisation is layered (docs/04-rbac.md): the route decides which
 * permission may reach it, and the policy decides whether the specific record
 * belongs to the actor's branch. The second layer runs from the controller, so
 * the trait belongs here rather than being repeated in each one.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
