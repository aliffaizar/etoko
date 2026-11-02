<?php

namespace App\Scopes;

use App\Services\Branch\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the branch the current request is restricted to (BR-3).
 *
 * Applied through the BelongsToBranch trait. When the branch context is
 * unrestricted (central staff, console, queued jobs) the scope adds nothing.
 *
 * @implements Scope<Model>
 */
class BranchScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Resolved here rather than injected: a global scope is instantiated once
        // when the model boots, which outlives the container instance that owns
        // the request-scoped BranchContext singleton.
        $context = app(BranchContext::class);

        if (! $context->isRestricted()) {
            return;
        }

        $builder->where($model->qualifyColumn('branch_id'), $context->branchId());
    }
}
