<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

/**
 * Who may run and close a stock count (P1-E4-T7).
 *
 * Counting and closing are separate permissions, and the matrix uses that:
 * warehouse staff hold `stock_opnames.create` so they can open a session and
 * type what they find, while `stock_opnames.complete` — the moment the
 * variances hit the ledger and the branch's figures change — is a decision for
 * a branch admin or the centre.
 *
 * Typing counted figures is therefore `recordCounts`, not `complete`: the two
 * must not collapse into one permission, or the person walking the shelves
 * would also be the person approving what they found.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class StockOpnamePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_opnames.view');
    }

    public function view(User $user, StockOpname $opname): bool
    {
        return $user->can('stock_opnames.view') && $this->isWithinReach($user, $opname);
    }

    public function create(User $user): bool
    {
        return $user->can('stock_opnames.create');
    }

    /**
     * Typing in what was found on the shelves. Moves no stock.
     */
    public function recordCounts(User $user, StockOpname $opname): bool
    {
        return $user->can('stock_opnames.create')
            && $this->isWithinReach($user, $opname)
            && $opname->isEditable();
    }

    /**
     * Closing the count, which is the moment the variances move stock.
     */
    public function complete(User $user, StockOpname $opname): bool
    {
        return $user->can('stock_opnames.complete')
            && $this->isWithinReach($user, $opname)
            && $opname->isEditable();
    }

    public function cancel(User $user, StockOpname $opname): bool
    {
        return $user->can('stock_opnames.create')
            && $this->isWithinReach($user, $opname)
            && $opname->isEditable();
    }

    /**
     * Whether the session's branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, StockOpname $opname): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $opname->branch_id;
    }
}
