<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Who may manage the category tree (P1-E3-T2).
 *
 * BR-1: products and categories are managed centrally, so every write demands
 * a central actor outright and not merely the permission. A branch admin holds
 * `categories.view` — they need to browse the catalogue their branch sells
 * from — and a hand-edited role that grants them `categories.update` still
 * must not let them reshape a tree every other branch shares.
 *
 * There is no branch narrowing here, because a category belongs to no branch.
 *
 * A Super Admin bypasses this policy entirely through the `Gate::before` check
 * in AppServiceProvider.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('categories.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->can('categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('categories.create') && $user->isCentral();
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('categories.update') && $user->isCentral();
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can('categories.delete') && $user->isCentral();
    }
}
