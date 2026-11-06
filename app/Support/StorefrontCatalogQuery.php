<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Pricing\CatalogPriceQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Which products a shopper sees, and in what order (P1-E5-T3, T4, T5, T7).
 *
 * **The catalogue is the company's, not a branch's** (ADR-031). One website,
 * one catalogue: every published product is listed at every branch, and the
 * price on it is the catalogue price. What stays per branch is **stock**,
 * because inventory really is independent (BR-2) — so a branch that does not
 * hold a product shows it as unavailable rather than making it vanish.
 *
 * That is why `branch_products` is **not** consulted here. A branch that has
 * switched a product off is saying "we do not stock this", which the storefront
 * states on the product (`ProductCardPresenter`) and which checkout will act on
 * in `P1-E7` — it is not a reason to hide the product from a shop that serves
 * every branch.
 *
 * Two things narrow the listing, and keeping them in one place is what stops
 * them from disagreeing:
 *
 * 1. **The catalogue's own gate** — `Product::published()`, an active category,
 *    and at least one active variant, because stock, carts and orders all point
 *    at a variant (ADR-020) and a product without one cannot be bought. Only
 *    the product's own category is checked: switching a category off already
 *    switches its whole subtree off (ADR-019), so the ancestors need no second
 *    walk.
 * 2. **The shopper's own filters** — category, price range, availability and
 *    search. Only the availability filter needs a branch, because only stock is
 *    per branch.
 *
 * The homepage rails and the catalogue page both start here, so a product
 * hidden from one is hidden from the other.
 *
 * Price is read through `CatalogPriceQuery` with **no branch**, which is what
 * makes the sort agree with the price on the card (ADR-029, ADR-031); stock is
 * read as what the branch's warehouses can still sell,
 * `quantity - reserved_quantity` (BR-6).
 *
 * Only what decides *which* products are on the page, and in what order, is
 * answered in SQL here. The figures the cards display come from
 * `ProductCardPresenter` and `EffectivePriceResolver`, which are the authority
 * on them.
 */
final class StorefrontCatalogQuery
{
    /**
     * What a shopper may sort by, as `sort` value => label.
     *
     * The whitelist lives here rather than in the form request because the
     * screen's select and the query's `ORDER BY` have to be the same list.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'latest' => 'Terbaru',
        'cheapest' => 'Harga termurah',
        'priciest' => 'Harga tertinggi',
        'bestselling' => 'Terlaris',
    ];

    public const DEFAULT_SORT = 'latest';

    /**
     * Availability filter values (`availability` => label).
     *
     * @var array<string, string>
     */
    public const AVAILABILITY = [
        'in_stock' => 'Tersedia di cabang ini',
    ];

    /**
     * Every product the shop sells, before the shopper's own filters.
     *
     * Takes no branch: the catalogue is company-wide (ADR-031).
     *
     * @return Builder<Product>
     */
    public static function offered(): Builder
    {
        return Product::query()
            ->published()
            ->whereHas('category', fn (Builder $category) => $category->active())
            ->whereHas('variants', fn (Builder $variants) => $variants->active());
    }

    /**
     * The offered set narrowed by the shopper's filters.
     *
     * No price or availability column is selected here on purpose. The cards
     * get both from `ProductCardPresenter`, which reads the variants and stock
     * rows it has eager-loaded anyway — so selecting them again would be two
     * correlated subqueries per row for figures nobody reads. SQL is used for
     * the price only where PHP cannot answer: deciding *which* products are on
     * the page, and in what order.
     *
     * @param  Branch  $stockBranch  Only the availability filter uses it; price is company-wide.
     * @param  array{search: string, category: string, min_price: string, max_price: string, availability: string, sort: string}  $filters
     * @return Builder<Product>
     */
    public static function filtered(Branch $stockBranch, array $filters): Builder
    {
        $query = self::offered();

        self::applySearch($query, $filters['search']);
        self::applyCategory($query, $filters['category']);
        self::applyPriceRange($query, $filters['min_price'], $filters['max_price']);

        if ($filters['availability'] === 'in_stock') {
            self::applyInStock($query, $stockBranch);
        }

        return $query;
    }

    /**
     * The filtered set in the shopper's chosen order.
     *
     * Every order ends with `products.id`, so two products sharing a price or
     * a publication minute never swap places between pages of the paginator.
     *
     * @param  array{search: string, category: string, min_price: string, max_price: string, availability: string, sort: string}  $filters
     * @return Builder<Product>
     */
    public static function ordered(Branch $stockBranch, array $filters): Builder
    {
        $query = self::filtered($stockBranch, $filters);
        // No branch: the storefront charges the catalogue price, so the order
        // has to be the catalogue's too (ADR-031).
        $price = CatalogPriceQuery::lowestPrice(null);

        return match ($filters['sort']) {
            'cheapest' => self::orderByPrice($query, $price, 'asc'),
            'priciest' => self::orderByPrice($query, $price, 'desc'),
            'bestselling' => $query->orderByDesc('products.sold_count')->orderBy('products.id'),
            default => $query->orderByDesc('products.published_at')->orderBy('products.id'),
        };
    }

    /**
     * Catalogue search over a product's own words (P1-E5-T5).
     *
     * MySQL gets its fulltext index *and* a `LIKE`, because the two answer
     * different questions: the index matches whole words and ranks them, while
     * someone typing "headph" into a search box expects the headphones to
     * appear before they finish the word. Other drivers — the test suite runs
     * on SQLite — have no fulltext index to use (docs/02-architecture.md), so
     * the `LIKE` is the whole search there.
     *
     * @param  Builder<Product>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $match) use ($search): void {
            $match->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.short_description', 'like', "%{$search}%");

            if ($match->getModel()->getConnection()->getDriverName() === 'mysql') {
                $match->orWhereFullText(['name', 'short_description'], $search);
            }
        });
    }

    /**
     * Narrows to a category and everything filed below it, so browsing
     * "Elektronik" shows the headphones filed two levels down.
     *
     * The filter carries a slug rather than an id: it is the same value the
     * `/kategori/{slug}` route uses, and a shared catalogue link should stay
     * readable. An unknown slug narrows to nothing rather than being ignored,
     * because silently showing the whole catalogue would look like a shop that
     * does not have the category at all.
     *
     * @param  Builder<Product>  $query
     */
    private static function applyCategory(Builder $query, string $slug): void
    {
        if ($slug === '') {
            return;
        }

        $category = Category::query()->active()->where('slug', $slug)->first();

        $query->whereIn(
            'products.category_id',
            $category instanceof Category
                ? [$category->getKey(), ...$category->descendantIds()]
                : [0],
        );
    }

    /**
     * Narrows by the catalogue price — the price every branch charges
     * (ADR-031).
     *
     * Phrased as `EXISTS` / `NOT EXISTS` over the product's options rather than
     * by comparing a scalar subquery, which is the same question in a shape the
     * query builder will take — see `CatalogPriceQuery::variantsPriced()`.
     *
     * @param  Builder<Product>  $query
     */
    private static function applyPriceRange(Builder $query, string $min, string $max): void
    {
        if ($min !== '') {
            // The cheapest option is at least the minimum, i.e. no option is
            // below it.
            $query->whereNotExists(
                CatalogPriceQuery::variantsPriced(null, '<', (int) $min),
            );
        }

        if ($max !== '') {
            $query->whereExists(
                CatalogPriceQuery::variantsPriced(null, '<=', (int) $max),
            );
        }
    }

    /**
     * Narrows to products the branch can actually sell a unit of right now.
     *
     * One sellable row is enough, and `quantity > reserved_quantity` is what
     * "sellable" means (BR-6) — no row can be negative, so "some row has
     * some left" and "the total left is above zero" are the same condition.
     *
     * @param  Builder<Product>  $query
     */
    private static function applyInStock(Builder $query, Branch $branch): void
    {
        $query->whereExists(
            DB::table('stocks as s')
                ->selectRaw('1')
                ->join('product_variants as sv', fn (JoinClause $join) => $join
                    ->on('sv.id', '=', 's.product_variant_id')
                    ->where('sv.is_active', true)
                    ->whereNull('sv.deleted_at'))
                ->whereColumn('sv.product_id', 'products.id')
                ->where('s.branch_id', $branch->getKey())
                ->whereColumn('s.quantity', '>', 's.reserved_quantity'),
        );
    }

    /**
     * @param  Builder<Product>  $query
     * @param  'asc'|'desc'  $direction
     * @return Builder<Product>
     */
    private static function orderByPrice(Builder $query, QueryBuilder $price, string $direction): Builder
    {
        return $query->orderBy($price, $direction)->orderBy('products.id');
    }
}
