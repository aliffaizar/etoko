<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Auth\Events\Login;

/**
 * Merges the guest's cart into the authenticated user's cart on sign-in
 * (P1-E6-T5).
 *
 * The session at this point still holds the guest's `cart_token`, because
 * Laravel regenerates the session ID on login but preserves the data —
 * so the token from before login is still readable here.
 *
 * The merge logic lives in `CartService::mergeGuestCart` so it can be tested
 * and called from other contexts (e.g. a future social login) without
 * duplicating the rules.
 */
class MergeGuestCart
{
    public function __construct(private readonly CartService $cartService) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $token = $this->cartService->guestToken();

        $this->cartService->mergeGuestCart($user, $token);
    }
}
