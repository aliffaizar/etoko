<?php

namespace App\Observers;

use App\Models\ProductAttribute;
use App\Observers\Concerns\FillsUniqueSlug;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps an attribute's slug unique and never empty (P1-E3-T8).
 *
 * The slug is what the storefront's filter URLs will be built from
 * (`?warna=merah`), so it is derived once here rather than at each call site.
 */
class ProductAttributeObserver
{
    use FillsUniqueSlug;

    public function creating(ProductAttribute $attribute): void
    {
        $this->fillSlugOnCreate($attribute);
    }

    public function updating(ProductAttribute $attribute): void
    {
        $this->fillSlugOnUpdate($attribute);
    }

    /**
     * @return Builder<ProductAttribute>
     */
    protected function slugLookupQuery(): Builder
    {
        return ProductAttribute::query();
    }
}
