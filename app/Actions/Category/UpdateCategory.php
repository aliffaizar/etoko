<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Updates a category and keeps the tree's activity invariant (ADR-019).
 *
 * An active category always has active ancestors, so the storefront can trust
 * `is_active` on its own instead of walking the path on every query. The two
 * halves of that invariant are enforced in different places: activating a
 * category under an inactive parent is refused by `UpdateCategoryRequest`,
 * and deactivating one takes its whole subtree down with it here, inside one
 * transaction so the tree is never left half-hidden.
 */
final class UpdateCategory
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            $wasActive = $category->is_active;

            $category->fill($attributes)->save();

            if ($wasActive && ! $category->is_active) {
                $this->deactivateSubtreeOf($category);
            }

            return $category;
        });
    }

    private function deactivateSubtreeOf(Category $category): void
    {
        $descendantIds = $category->descendantIds();

        if ($descendantIds === []) {
            return;
        }

        // Updated row by row rather than in one statement, so each hidden
        // subcategory lands in `audit_logs` the way a manual change would (BR-9).
        Category::query()
            ->whereKey($descendantIds)
            ->where('is_active', true)
            ->get()
            ->each(fn (Category $descendant) => $descendant->update(['is_active' => false]));
    }
}
