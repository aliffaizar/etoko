<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

/**
 * Who may read a parcel and who may send one (P2-E2-T4).
 *
 * Shipments belong to the branch that fulfils the order, so this is not
 * central-only: the branch that packs the goods is the branch that walks them
 * to the courier's counter. BR-3 keeps each branch to its own, and because
 * `shipments` carries `BelongsToBranch` with a `branch_id` copied from the
 * order (ADR-038), another branch's shipment id answers 404 before this policy
 * is consulted at all — the refusals here are the second line, not the first.
 *
 * Two abilities rather than one, because they are two different decisions and
 * the permission matrix separates them (docs/04-rbac.md, "Pengiriman & resi"):
 *
 * - **`view`** — `shipments.view`. Reading where a parcel is.
 * - **`manage`** — `shipments.manage`. Recording a waybill, moving a parcel
 *   along, asking a courier for fresh scans. All three are the same decision
 *   from different ends, so they share one ability rather than inventing
 *   permissions the matrix does not have.
 *
 * Handing an order's goods over for the **first** time is not here but on
 * `OrderPolicy` — `dispatchShipment` and `confirmPickup` — because at that
 * point there may be no parcel to authorise against: an order whose delivery
 * was arranged by hand has no `shipments` row until the branch says which
 * courier it used. Both are still gated on `shipments.manage`, so the
 * permission matrix reads the same from either side.
 *
 * A customer's own parcels are **not** governed here. A shopper holds no
 * `shipments.*` permission, and their tracking is reached through their order
 * in `Shop\OrderController`, scoped by `user_id`. Letting a customer through
 * this policy would mean deciding they may "view any shipment", which is not
 * what they may do.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('shipments.view');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.view') && $this->isWithinReach($user, $shipment->branch_id);
    }

    /**
     * Recording a waybill, or moving a parcel along.
     */
    public function manage(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.manage') && $this->isWithinReach($user, $shipment->branch_id);
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
