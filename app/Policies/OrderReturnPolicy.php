<?php

namespace App\Policies;

use App\Models\OrderReturn;
use App\Models\User;

/**
 * Who may read a return request and who may decide it (P2-E4-T3).
 *
 * Returns belong to the branch that fulfilled the order, so this is not
 * central-only: the branch that sent the goods out is the branch the parcel
 * comes back to, and the branch that opens the box is the only one that can
 * say what condition the goods are in. BR-3 keeps each branch to its own, and
 * because `returns` carries `BelongsToBranch` with a `branch_id` copied from
 * the order (ADR-038), another branch's return id answers 404 before this
 * policy is consulted at all — the refusals here are the second line, not the
 * first.
 *
 * Two abilities rather than four, because the permission matrix has two
 * (docs/04-rbac.md, "Retur & refund"):
 *
 * - **`view`** — `returns.view`. Reading the queue and a request's detail.
 * - **`decide`** — `returns.approve`. Agreeing to take goods back, turning a
 *   request down, booking the parcel in, and closing the request. All four are
 *   the same decision at different points of the same parcel, so they share
 *   one ability rather than inventing permissions the matrix does not have.
 *
 * Sending the **money** back is not here: it is `RefundPolicy::process`, on
 * `refunds.process`, and it is central (ADR-049). A branch admin may take
 * goods back without being able to pay for them, which is the separation the
 * matrix asks for.
 *
 * `decide` also asks the **request**, not just the actor: a request that has
 * already been rejected cannot be approved, and the reasons live on
 * `OrderReturn` so the endpoint and the `can.*` flags the screens read cannot
 * disagree.
 *
 * A customer's own requests are **not** governed here. A shopper holds no
 * `returns.*` permission, and their requests are reached through their order
 * in `Shop\ReturnController`, scoped by `user_id`. Letting a customer through
 * this policy would mean deciding they may "view any return", which is not
 * what they may do.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class OrderReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('returns.view');
    }

    public function view(User $user, OrderReturn $return): bool
    {
        return $user->can('returns.view') && $this->isWithinReach($user, $return->branch_id);
    }

    /**
     * Agreeing to take goods back, turning a request down, booking the parcel
     * in, or closing the request.
     *
     * Whether *this* request may move is asked of the request itself by the
     * endpoint; this answers whether the actor may decide at all. Keeping the
     * two apart is what lets a detail screen show "Setujui" greyed out with
     * its reason rather than hiding the branch's own queue.
     */
    public function decide(User $user, OrderReturn $return): bool
    {
        return $user->can('returns.approve') && $this->isWithinReach($user, $return->branch_id);
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
