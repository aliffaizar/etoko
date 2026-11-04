<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Who may manage the catalogue (P1-E3-T5).
 *
 * BR-1 names this policy directly: products are managed centrally, so every
 * write demands a central actor outright and not merely the permission. A
 * branch admin, warehouse staff and a cashier all hold `products.view` —
 * they pick products on stock and POS screens — and none of them may change
 * a catalogue every other branch shares.
 *
 * There is no branch narrowing here, because a product belongs to no branch.
 * What a branch decides for itself is availability (`branch_products`,
 * P1-E3-T9) and price (`branch_product_prices`, P1-E3-T10), which carry their
 * own permissions.
 *
 * A Super Admin bypasses this policy entirely through the `Gate::before` check
 * in AppServiceProvider.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create') && $user->isCentral();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.update') && $user->isCentral();
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete') && $user->isCentral();
    }
}
