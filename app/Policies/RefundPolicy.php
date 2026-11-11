<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

/**
 * Who may read a refund and who may make one (P2-E4-T4).
 *
 * Refunds carry a `branch_id` copied from the order (ADR-038) so the table can
 * be branch-scoped like every other transactional one: a branch reads what
 * went back against its own orders without a join, and another branch's refund
 * id answers 404 before this policy is consulted (ADR-027).
 *
 * Reading and deciding are split along the line the matrix draws
 * (docs/04-rbac.md):
 *
 * - **`view`** — `returns.view`, which a branch admin holds. Seeing that a
 *   customer has been paid back is part of answering for the order.
 * - **`process`** — `refunds.process`, and **central only, explicitly**
 *   (ADR-049). Not merely a permission check: stated as `isCentral()` so the
 *   Role screen cannot hand a branch the company's bank account by accident.
 *   It is the same line `PaymentPolicy::refund` already drew for the gate this
 *   flow was built behind.
 *
 * `process` is asked against a `Refund` for settling or failing an existing
 * one. Opening a refund has no row to authorise against yet, so that is
 * `OrderPolicy::refund` — gated on the same permission, so the matrix reads
 * the same from either side.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('returns.view');
    }

    public function view(User $user, Refund $refund): bool
    {
        return $user->can('returns.view') && $this->isWithinReach($user, $refund->branch_id);
    }

    /**
     * Settling a refund, or recording that it failed.
     */
    public function process(User $user, Refund $refund): bool
    {
        return $user->isCentral()
            && $user->can('refunds.process')
            && $this->isWithinReach($user, $refund->branch_id);
    }

    /**
     * Whether the branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, int $branchId): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $branchId;
    }
}
