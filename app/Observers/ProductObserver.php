<?php

namespace App\Observers;

use App\Models\Product;
use App\Observers\Concerns\FillsUniqueSlug;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a product's slug unique and never empty (P1-E3-T8).
 *
 * Soft-deleted products count as taking their slug: order history still points
 * at them, and a restored product should come back on the URL it had.
 */
class ProductObserver
{
    use FillsUniqueSlug;

    public function creating(Product $product): void
    {
        $this->fillSlugOnCreate($product);
    }

    public function updating(Product $product): void
    {
        $this->fillSlugOnUpdate($product);
    }

    /**
     * @return Builder<Product>
     */
    protected function slugLookupQuery(): Builder
    {
        return Product::withTrashed();
    }
}
