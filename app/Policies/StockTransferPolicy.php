<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

/**
 * Who may take each step of a stock transfer (P2-E3, docs/04-rbac.md).
 *
 * A transfer has two ends, and each step belongs to one of them:
 *
 * | Ability | Permission | Who |
 * | --- | --- | --- |
 * | view | `…view` | either end, or central |
 * | create | `…request` | the destination (its own warehouse), or central for any |
 * | cancel | `…request` | the destination, or central — until shipped |
 * | approve / reject | `…approve` | the origin, or central — never the destination |
 * | ship | `…ship` | the origin only |
 * | receive | `…receive` | the destination only |
 *
 * Shipping and receiving are physical acts at one warehouse, so they are not
 * granted to central staff even though Admin Pusat holds the permissions: the
 * person who says "it left" or "it arrived" has to be standing there. A Super
 * Admin bypasses this policy entirely through `Gate::before`.
 *
 * The destination never approves its own request: that would make the
 * approval mean nothing.
 */
class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_transfers.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.view') && $this->isParty($user, $transfer);
    }

    public function create(User $user): bool
    {
        return $user->can('stock_transfers.request');
    }

    public function approve(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.approve')
            && $this->speaksForOrigin($user, $transfer)
            && $transfer->approvalBlockedReason() === null;
    }

    public function reject(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.approve')
            && $this->speaksForOrigin($user, $transfer)
            && $transfer->rejectionBlockedReason() === null;
    }

    public function ship(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.ship')
            && $transfer->isFromBranch($user->branch_id)
            && $transfer->shippingBlockedReason() === null;
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.receive')
            && $transfer->isToBranch($user->branch_id)
            && $transfer->receivingBlockedReason() === null;
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.request')
            && ($user->isCentral() || $transfer->isToBranch($user->branch_id))
            && $transfer->cancellationBlockedReason() === null;
    }

    private function isParty(User $user, StockTransfer $transfer): bool
    {
        return $user->isCentral()
            || $transfer->isFromBranch($user->branch_id)
            || $transfer->isToBranch($user->branch_id);
    }

    /**
     * The origin branch, or central on its behalf — but never the requester's
     * own branch, even for a central account that happens to carry one.
     */
    private function speaksForOrigin(User $user, StockTransfer $transfer): bool
    {
        if ($transfer->isToBranch($user->branch_id)) {
            return false;
        }

        return $user->isCentral() || $transfer->isFromBranch($user->branch_id);
    }
}
