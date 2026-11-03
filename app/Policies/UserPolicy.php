<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Who may manage which staff account.
 *
 * This is the third layer of the three described in docs/04-rbac.md: the route
 * middleware decides whether the actor may reach the endpoint at all, the
 * permission decides whether the role may perform the action, and this policy
 * decides whether *this* account is theirs to touch.
 *
 * Two rules do the work:
 *
 * - **Branch scope (BR-3).** A branch-bound actor only ever sees and edits staff
 *   of their own branch. Central accounts (`branch_id` null) are therefore out
 *   of their reach as well.
 * - **Role ceiling.** Nobody but a Super Admin may touch a Super Admin account,
 *   and nobody may grant a role they could not hold themselves
 *   (`UserRole::grantableRoles()`).
 *
 * A Super Admin bypasses this policy entirely through the `Gate::before` check
 * in AppServiceProvider, so the guards against acting on one's own account live
 * in the controller where they apply to everyone.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view') && $this->isWithinReach($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create') && $this->grantableRoles($user) !== [];
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.update') && $this->isWithinReach($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.delete') && $this->isWithinReach($user, $model);
    }

    /**
     * Activating or deactivating an account is an update of its own, because it
     * decides whether the person can still sign in (P1-E1-T3).
     */
    public function toggleActive(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    /**
     * Setting someone else's password without knowing the old one.
     */
    public function resetPassword(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    /**
     * The roles the actor may grant, as role values.
     *
     * @return array<int, string>
     */
    public function grantableRoles(User $user): array
    {
        $roles = $user->appRole()?->grantableRoles() ?? [];

        return array_map(fn (UserRole $role): string => $role->value, $roles);
    }

    /**
     * Whether the account falls inside the actor's branch scope and role ceiling.
     */
    private function isWithinReach(User $user, User $model): bool
    {
        if ($model->hasAppRole(UserRole::SuperAdmin)) {
            return false;
        }

        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $model->branch_id;
    }
}
