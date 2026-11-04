<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Updates a branch's own details.
 *
 * Only one branch may carry `is_default`, because the storefront falls back to
 * it when a visitor has not picked a branch yet. Promoting a branch therefore
 * demotes the previous holder in the same transaction, so the flag is never
 * held twice and never by nobody. Clearing the flag directly is refused by
 * UpdateBranchRequest — another branch has to be promoted instead.
 *
 * Warehouses and operating hours are not touched here; they have their own
 * endpoints (P1-E2-T4, P1-E2-T6).
 */
final class UpdateBranch
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Branch $branch, array $attributes): Branch
    {
        return DB::transaction(function () use ($branch, $attributes): Branch {
            $branch->fill($attributes)->save();

            if ($branch->is_default) {
                Branch::query()
                    ->whereKeyNot($branch->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return $branch;
        });
    }
}
