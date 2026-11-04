<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Who may manage which branch (P1-E2-T7).
 *
 * This is the third layer of the three described in docs/04-rbac.md: the route
 * middleware decides whether the actor may reach the endpoint at all, the
 * permission decides whether the role may perform the action, and this policy
 * decides whether *this* branch is theirs to touch.
 *
 * The branch matrix gives a branch admin `branches.view` and `branches.update`
 * so they can keep their own address, opening hours and warehouses current
 * (BR-3). Creating and deleting branches stays central, and `delete` demands a
 * central actor outright: a branch admin holding `branches.delete` through a
 * hand-edited role must still not be able to delete the branch they work in.
 *
 * `Branch` carries no branch global scope — it *is* the branch — so every query
 * that lists branches narrows itself the way BranchController does.
 *
 * A Super Admin bypasses this policy entirely through the `Gate::before` check
 * in AppServiceProvider.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('branches.view') && $this->isWithinReach($user, $branch);
    }

    public function create(User $user): bool
    {
        return $user->can('branches.create') && $user->isCentral();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.update') && $this->isWithinReach($user, $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('branches.delete') && $user->isCentral();
    }

    /**
     * Adding, editing and removing the branch's warehouses (P1-E2-T6).
     *
     * Warehouses have no screen of their own: they are edited on the branch
     * they belong to, so the branch decides who may reach them.
     */
    public function manageWarehouses(User $user, Branch $branch): bool
    {
        return $user->can('warehouses.manage') && $this->isWithinReach($user, $branch);
    }

    /**
     * Moving an existing staff account into this branch (P1-E2-T5).
     *
     * Central only, because every candidate account sits outside the branch by
     * definition and a branch admin may not see — let alone edit — an account
     * of another branch (BR-3). A branch admin staffs their branch by creating
     * accounts at `/admin/users`, which their `users.create` permission covers.
     *
     * `UserPolicy` still has the last word on the account being moved.
     */
    public function assignStaff(User $user, Branch $branch): bool
    {
        return $user->can('users.update') && $user->isCentral() && $this->isWithinReach($user, $branch);
    }

    /**
     * Whether the branch falls inside the actor's branch scope (BR-3).
     */
    private function isWithinReach(User $user, Branch $branch): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $branch->getKey();
    }
}
