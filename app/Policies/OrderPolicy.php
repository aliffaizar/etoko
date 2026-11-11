<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Who may read and move an order (P1-E7-T8, T9, T10).
 *
 * Orders are the branch's own work, so unlike the catalogue this is not
 * central-only: the branch that fulfils an order is the one that moves it
 * along. BR-3 keeps each branch to its own orders, and because
 * `SubstituteBindings` runs after `SetBranchContext` (ADR-027) another
 * branch's order id in the URL answers 404 before this policy is consulted at
 * all — the refusals here are the second line, not the first.
 *
 * Five of the eight are deliberately narrower than "has the permission":
 *
 * - **`assignBranch` is central only.** Choosing which branch serves a
 *   customer is a company decision; a branch admin who could hand work to a
 *   neighbour could also take it from one. The permission alone is not enough,
 *   so granting `orders.assign_branch` on the Role screen does not open it.
 * - **`cancel` also asks the order.** A status that cannot reach `cancelled`
 *   is not cancellable by anybody, which `Order::cancellationBlockedReason()`
 *   answers for both this policy and the endpoint.
 * - **`dispatchShipment` and `confirmPickup` ask the order too**, and are
 *   gated on `shipments.manage` rather than an `orders.*` permission (P2-E2).
 *   They sit here rather than on `ShipmentPolicy` because an order whose
 *   delivery was arranged by hand has no parcel to authorise against until the
 *   branch says which courier it used.
 * - **`refund` is central only as well**, on `refunds.process`, and lives
 *   here for the same reason: opening a refund is what is being asked for, so
 *   there is no `Refund` row to authorise against (P2-E4-T4).
 *
 * A customer's own orders are **not** governed here: a shopper holds no
 * `orders.*` permission, and their own history is scoped by `user_id` in
 * `Shop\OrderController`. Letting a customer through this policy would mean
 * deciding they may "view any order", which is not what they may do.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('orders.view') && $this->isWithinReach($user, $order);
    }

    /**
     * Moving the order along its state machine.
     *
     * Whether a *particular* move is legal is `OrderStatus`'s business, checked
     * inside `TransitionOrderStatus` while it holds the row lock. This only
     * says who is allowed to try.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        return $user->can('orders.update_status')
            && $this->isWithinReach($user, $order)
            && ! $order->status->isFinal();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->can('orders.cancel')
            && $this->isWithinReach($user, $order)
            && $order->cancellationBlockedReason(asCustomer: false) === null;
    }

    /**
     * Handing the order to another branch.
     *
     * Central only, explicitly — not merely a permission check — so the Role
     * screen cannot grant it to a branch account by accident.
     */
    public function assignBranch(User $user, Order $order): bool
    {
        return $user->isCentral()
            && $user->can('orders.assign_branch')
            && $order->branchReassignmentBlockedReason() === null;
    }

    /**
     * Handing the order's goods to a courier (P2-E2-T4).
     *
     * Gated on `shipments.manage` rather than on an `orders.*` permission,
     * because that is the decision being taken and the matrix says so
     * (docs/04-rbac.md, "Pengiriman & resi"). It lives on this policy rather
     * than on `ShipmentPolicy` because at this point there may be no parcel to
     * authorise against: an order whose delivery was arranged by hand has no
     * `shipments` row until the branch says which courier it used.
     *
     * Also asks the order, through `Order::dispatchBlockedReason()`, so the
     * same sentence answers for this policy and for the endpoint and a button
     * is never offered for something the server will refuse.
     */
    public function dispatchShipment(User $user, Order $order): bool
    {
        return $user->can('shipments.manage')
            && $this->isWithinReach($user, $order)
            && $order->dispatchBlockedReason() === null;
    }

    /**
     * Handing a Click & Collect order over at the counter (P2-E2-T7).
     *
     * The same decision as a dispatch — the goods leaving the branch — taken
     * at the till instead of the courier's counter, so it shares the
     * permission: somebody who may not record a waybill may not give the
     * parcel away either.
     */
    public function confirmPickup(User $user, Order $order): bool
    {
        return $user->can('shipments.manage')
            && $this->isWithinReach($user, $order)
            && $order->pickupConfirmationBlockedReason() === null;
    }

    /**
     * Opening a refund against the order (P2-E4-T4).
     *
     * Central only, explicitly — not merely a permission check — so the Role
     * screen cannot hand a branch the company's bank account by accident
     * (ADR-049). It lives on this policy rather than on `RefundPolicy` for the
     * same reason `dispatchShipment` does: at this point there is no refund
     * row to authorise against, because creating one is the thing being asked
     * for.
     *
     * Also asks the order, so a refund is never offered against an order that
     * has nothing left to give back.
     */
    public function refund(User $user, Order $order): bool
    {
        return $user->isCentral()
            && $user->can('refunds.process')
            && $this->isWithinReach($user, $order)
            && $order->refundableAmount() > 0;
    }

    /**
     * Whether the order's branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, Order $order): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $order->branch_id;
    }
}
