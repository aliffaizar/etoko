<?php

namespace App\Policies;

use App\Models\StockEntry;
use App\Models\User;

/**
 * Who may draft and post goods-in documents (P1-E4-T5).
 *
 * Receiving stock is the branch's own work, so unlike the catalogue this is
 * not central-only: a branch admin and their warehouse staff type the delivery
 * note and post it. BR-3 keeps each of them to their own branch, and the
 * matrix gives a cashier none of these permissions at all.
 *
 * `post` and `delete` are separate permissions from `create` because they are
 * separate decisions: `stock_entries.post` is the commitment that the goods
 * really arrived, and the baseline withholds `stock_entries.delete` from
 * warehouse staff so a draft somebody else is working on cannot be thrown away
 * by whoever happens to open it.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class StockEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_entries.view');
    }

    public function view(User $user, StockEntry $entry): bool
    {
        return $user->can('stock_entries.view') && $this->isWithinReach($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->can('stock_entries.create');
    }

    /**
     * Editing a draft. A posted document is never editable, whoever asks:
     * `StockEntry::isEditable()` is what says so, and the actions refuse it
     * independently of this policy.
     */
    public function update(User $user, StockEntry $entry): bool
    {
        return $user->can('stock_entries.create')
            && $this->isWithinReach($user, $entry)
            && $entry->isEditable();
    }

    /**
     * Committing the document, which is the moment stock moves.
     */
    public function post(User $user, StockEntry $entry): bool
    {
        return $user->can('stock_entries.post')
            && $this->isWithinReach($user, $entry)
            && $entry->isEditable();
    }

    /**
     * Abandoning a draft. Not an undo: a posted document stays.
     */
    public function cancel(User $user, StockEntry $entry): bool
    {
        return $user->can('stock_entries.create')
            && $this->isWithinReach($user, $entry)
            && $entry->isEditable();
    }

    /**
     * Deleting a draft outright, leaving no trace of it.
     *
     * Only ever a draft: a posted document is explained by its ledger rows and
     * must stay findable from them.
     */
    public function delete(User $user, StockEntry $entry): bool
    {
        return $user->can('stock_entries.delete')
            && $this->isWithinReach($user, $entry)
            && $entry->isEditable();
    }

    /**
     * Whether the document's branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, StockEntry $entry): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $entry->branch_id;
    }
}
