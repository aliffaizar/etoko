<?php

namespace App\Observers;

use App\Actions\Product\RefreshLowestPrice;
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

    public function __construct(private readonly RefreshLowestPrice $refreshLowestPrice) {}

    public function creating(Product $product): void
    {
        $this->fillSlugOnCreate($product);
    }

    public function updating(Product $product): void
    {
        $this->fillSlugOnUpdate($product);
    }

    /**
     * A product that is not featured has no place in the featured order
     * (P2-E8-T4), so the position is cleared whenever the flag is.
     */
    public function saving(Product $product): void
    {
        if (! $product->is_featured) {
            $product->featured_position = null;
        }
    }

    /**
     * The base price is the fallback every variant without its own price
     * inherits, so a product save refreshes its catalogue figure (ADR-066).
     */
    public function saved(Product $product): void
    {
        $this->refreshLowestPrice->handle($product->getKey());
    }

    /**
     * @return Builder<Product>
     */
    protected function slugLookupQuery(): Builder
    {
        return Product::withTrashed();
    }
}
