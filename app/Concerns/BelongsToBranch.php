<?php

namespace App\Concerns;

use App\Models\Branch;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds the branch relationship and the branch global scope to a model (BR-3).
 *
 * @phpstan-require-extends Model
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Query the model across every branch.
     *
     * Only for central reporting, jobs and commands. State the reason at the
     * call site.
     *
     * @return Builder<static>
     */
    public static function acrossBranches(): Builder
    {
        return static::query()->withoutGlobalScope(BranchScope::class);
    }
}
