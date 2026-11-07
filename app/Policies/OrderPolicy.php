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
 * Two of the five are deliberately narrower than "has the permission":
 *
 * - **`assignBranch` is central only.** Choosing which branch serves a
 *   customer is a company decision; a branch admin who could hand work to a
 *   neighbour could also take it from one. The permission alone is not enough,
 *   so granting `orders.assign_branch` on the Role screen does not open it.
 * - **`cancel` also asks the order.** A status that cannot reach `cancelled`
 *   is not cancellable by anybody, which `Order::cancellationBlockedReason()`
 *   answers for both this policy and the endpoint.
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
