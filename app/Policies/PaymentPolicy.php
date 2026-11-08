<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Who may read and settle a payment (P2-E1-T4).
 *
 * Payments belong to the branch that fulfils the order, so this is not
 * central-only: the branch that packs the goods is the branch that checks the
 * transfer landed. BR-3 keeps each branch to its own, and because `payments`
 * carries `BelongsToBranch` with a `branch_id` copied from the order
 * (ADR-038), another branch's payment id in the URL answers 404 before this
 * policy is consulted at all — the refusals here are the second line, not the
 * first.
 *
 * Three abilities rather than one, because they are three different decisions
 * and the permission matrix separates them (docs/04-rbac.md):
 *
 * - **`view`** — `payments.view`. A cashier holds it: seeing whether an order
 *   is paid is part of serving the customer in front of them.
 * - **`settle`** — `payments.verify`. Confirming money arrived, or rejecting a
 *   receipt that does not add up. A cashier does **not** hold it; a branch
 *   admin does. Both verifying and refusing are the same decision from
 *   opposite ends, so they share one ability rather than inventing a
 *   permission the matrix does not have.
 * - **`refund`** — `payments.refund`, central only. Sending money back is a
 *   company decision; the flow itself is `P2-E4`.
 *
 * `settle` also asks the **payment**, not just the actor:
 * `Payment::verificationBlockedReason()` answers for this policy and for the
 * endpoint alike, so a button is never offered for something the server will
 * turn down.
 *
 * A customer's own payments are **not** governed here. A shopper holds no
 * `payments.*` permission, and their own attempts are reached through their
 * order in `Shop\PaymentController`, scoped by `user_id`. Letting a customer
 * through this policy would mean deciding they may "view any payment", which
 * is not what they may do.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view') && $this->isWithinReach($user, $payment);
    }

    /**
     * Deciding the fate of an attempt: confirming it, or turning it down.
     */
    public function settle(User $user, Payment $payment): bool
    {
        return $user->can('payments.verify')
            && $this->isWithinReach($user, $payment)
            && $payment->verificationBlockedReason() === null;
    }

    /**
     * Sending money back.
     *
     * Central only, explicitly — not merely a permission check — so the Role
     * screen cannot grant it to a branch account by accident. The refund flow
     * arrives in `P2-E4`; this is the gate it will be built behind.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isCentral()
            && $user->can('payments.refund')
            && $this->isWithinReach($user, $payment);
    }

    /**
     * Whether the payment's branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, Payment $payment): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $payment->branch_id;
    }
}
