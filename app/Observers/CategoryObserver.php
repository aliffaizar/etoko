<?php

namespace App\Observers;

use App\Models\Category;
use App\Observers\Concerns\FillsUniqueSlug;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a category's slug unique and never empty (P1-E3-T8).
 */
class CategoryObserver
{
    use FillsUniqueSlug;

    public function creating(Category $category): void
    {
        $this->fillSlugOnCreate($category);
    }

    public function updating(Category $category): void
    {
        $this->fillSlugOnUpdate($category);
    }

    /**
     * @return Builder<Category>
     */
    protected function slugLookupQuery(): Builder
    {
        return Category::withTrashed();
    }
}
