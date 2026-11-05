<?php

namespace App\Policies;

use App\Models\StockIssue;
use App\Models\User;

/**
 * Who may draft and post goods-out documents (P1-E4-T6).
 *
 * The same shape as `StockEntryPolicy`, with one deliberate difference: there
 * is no `delete`. The matrix grants `stock_issues.delete` to nobody, because a
 * goods-out note is the record of something going missing — a draft that can
 * be made to disappear is a gap in exactly the trail that matters. An abandoned
 * note is cancelled and stays visible.
 *
 * A Super Admin bypasses this policy entirely through `Gate::before`.
 */
class StockIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_issues.view');
    }

    public function view(User $user, StockIssue $issue): bool
    {
        return $user->can('stock_issues.view') && $this->isWithinReach($user, $issue);
    }

    public function create(User $user): bool
    {
        return $user->can('stock_issues.create');
    }

    public function update(User $user, StockIssue $issue): bool
    {
        return $user->can('stock_issues.create')
            && $this->isWithinReach($user, $issue)
            && $issue->isEditable();
    }

    public function post(User $user, StockIssue $issue): bool
    {
        return $user->can('stock_issues.post')
            && $this->isWithinReach($user, $issue)
            && $issue->isEditable();
    }

    public function cancel(User $user, StockIssue $issue): bool
    {
        return $user->can('stock_issues.create')
            && $this->isWithinReach($user, $issue)
            && $issue->isEditable();
    }

    /**
     * Whether the document's branch falls inside the actor's scope (BR-3).
     */
    private function isWithinReach(User $user, StockIssue $issue): bool
    {
        if ($user->isCentral()) {
            return true;
        }

        return $user->branch_id !== null && $user->branch_id === $issue->branch_id;
    }
}
