<?php

namespace App\Policies;

use App\Models\ProductAttribute;
use App\Models\User;

/**
 * Who may manage the attributes variants are built from (P1-E3-T6).
 *
 * Attributes and their values are catalogue-wide: "Merah" means the same thing
 * in every product and every branch, which is what lets the storefront offer
 * one colour filter across categories. They are therefore central master data
 * under BR-1, and this policy demands a central actor for every write exactly
 * as `ProductPolicy` does.
 *
 * They carry no permissions of their own: an attribute exists only to build a
 * product's variants, so the `products.*` permissions are what gate it. A
 * branch admin, warehouse staff and a cashier hold `products.view` and read
 * the same list — none of them may rename an option every branch sells under
 * (ADR-021).
 *
 * A Super Admin bypasses this policy entirely through the `Gate::before` check
 * in AppServiceProvider.
 */
class ProductAttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, ProductAttribute $attribute): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create') && $user->isCentral();
    }

    public function update(User $user, ProductAttribute $attribute): bool
    {
        return $user->can('products.update') && $user->isCentral();
    }

    public function delete(User $user, ProductAttribute $attribute): bool
    {
        return $user->can('products.delete') && $user->isCentral();
    }
}
