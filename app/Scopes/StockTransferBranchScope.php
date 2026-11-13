<?php

namespace App\Scopes;

use App\Models\StockTransfer;
use App\Services\Branch\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits stock transfers to the ones the current branch is a party to (P2-E3-T5).
 *
 * The `BranchScope` of every other stock document matches one `branch_id`; a
 * transfer has two, and either end may look at it. A third branch may not, and
 * because this is a global scope a transfer bound from the URL by that third
 * branch is simply not found — a 404, not a 403, the way every other
 * branch-scoped record answers (ADR-027).
 *
 * Central staff, jobs and the console have no restriction and see everything.
 *
 * @implements Scope<StockTransfer>
 */
class StockTransferBranchScope implements Scope
{
    /**
     * @param  Builder<covariant StockTransfer>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Resolved here for the same reason as `BranchScope`: the scope
        // outlives the request-scoped context it reads.
        $context = app(BranchContext::class);

        if (! $context->isRestricted()) {
            return;
        }

        $branchId = (int) $context->branchId();

        $builder->where(fn (Builder $either) => $either
            ->where($model->qualifyColumn('from_branch_id'), $branchId)
            ->orWhere($model->qualifyColumn('to_branch_id'), $branchId));
    }
}
