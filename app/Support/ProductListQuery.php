<?php

namespace App\Support;

use App\Enums\ProductPublicationState;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * The product listing's filters, as a query (P1-E3-T5, P1-E3-T11).
 *
 * Extracted because two places need the same filtered set: the screen that
 * paginates it and the CSV export that streams it. "Export what I am looking
 * at" is only true if the two read the same rules, and a second copy of a
 * filter list is a second chance to get one of them wrong.
 *
 * The filter array is the one `IndexProductRequest::filters()` returns, which
 * is also where the sort column is whitelisted.
 */
final class ProductListQuery
{
    /**
     * @param  array{search: string, category: string, state: string, type: string, featured: string, sort: string, direction: string}  $filters
     * @return Builder<Product>
     */
    public static function from(array $filters): Builder
    {
        return Product::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('slug', 'like', "%{$filters['search']}%")
                    ->orWhereHas('variants', fn (Builder $variants) => $variants
                        ->where('sku', 'like', "%{$filters['search']}%")
                        ->orWhere('barcode', 'like', "%{$filters['search']}%")),
            ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->whereIn('category_id', self::categoryBranchIds((int) $filters['category'])))
            ->when($filters['state'] !== '', fn (Builder $query) => $query
                ->withPublicationState(ProductPublicationState::from($filters['state'])))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']))
            ->when($filters['featured'] !== '', fn (Builder $query) => $query
                ->where('is_featured', $filters['featured'] === 'yes'));
    }

    /**
     * The same set, ordered as the listing orders it.
     *
     * The export does not use this: `chunkById` imposes its own order, and a
     * CSV someone is going to sort in a spreadsheet anyway gains nothing from
     * it.
     *
     * @param  array{search: string, category: string, state: string, type: string, featured: string, sort: string, direction: string}  $filters
     * @return Builder<Product>
     */
    public static function ordered(array $filters): Builder
    {
        return self::from($filters)
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * The chosen category and everything below it, so filtering by a parent
     * includes the products filed under its subcategories.
     *
     * @return array<int, int>
     */
    public static function categoryBranchIds(int $categoryId): array
    {
        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return [$categoryId];
        }

        return [$category->getKey(), ...$category->descendantIds()];
    }
}
