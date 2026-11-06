<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\IndexCatalogRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Branch\StorefrontBranch;
use App\Support\CategoryTree;
use App\Support\ProductCardPresenter;
use App\Support\StorefrontCatalogQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue a shopper browses (P1-E5-T3, T4, T5, T7).
 *
 * One screen answers three entry points — browsing everything, browsing a
 * category, and searching — because they differ only in which filter arrives
 * pre-applied. A separate search results page would be the same grid, the same
 * filters and the same pagination with a different heading, and the two would
 * drift.
 *
 * The catalogue and its prices are the company's, the same at every branch
 * (ADR-031); only the stock badge and the "tersedia di cabang ini" filter read
 * the branch the shopper is in (BR-2). The sort and the price filter read the
 * same catalogue price the cards show, through `CatalogPriceQuery`.
 */
class CatalogController extends Controller
{
    private const PER_PAGE = 12;

    /**
     * `/produk` — the whole catalogue, plus whatever the query string narrows
     * it to.
     */
    public function index(IndexCatalogRequest $request, StorefrontBranch $storefront, ProductCardPresenter $cards): Response
    {
        return $this->screen($request, $storefront->currentOrFail(), $cards, null);
    }

    /**
     * `/kategori/{slug}` — the same catalogue, entered through a category.
     *
     * A route of its own rather than a query parameter, so the category's page
     * has a readable, shareable address and a heading of its own. The filter
     * itself is still the category filter: the slug is handed to the same
     * `filters` array, which is what keeps one query behind both entry points.
     */
    public function category(
        IndexCatalogRequest $request,
        Category $category,
        StorefrontBranch $storefront,
        ProductCardPresenter $cards,
    ): Response {
        abort_unless($category->is_active, 404);

        return $this->screen($request, $storefront->currentOrFail(), $cards, $category);
    }

    /**
     * The catalogue screen, with the category either chosen in the URL or
     * filtered in the query string.
     */
    private function screen(
        IndexCatalogRequest $request,
        Branch $branch,
        ProductCardPresenter $cards,
        ?Category $category,
    ): Response {
        $filters = $request->filters();

        if ($category instanceof Category) {
            $filters['category'] = $category->slug;
        }

        return Inertia::render('shop/catalog', [
            'products' => $this->paginatedProducts($branch, $filters, $cards),
            'filters' => $filters,
            'category' => $this->categoryHeading($category),
            'options' => [
                'categories' => $this->categoryOptions(),
                'sorts' => $this->optionsFrom(StorefrontCatalogQuery::SORTS),
                'availability' => $this->optionsFrom(StorefrontCatalogQuery::AVAILABILITY),
            ],
        ]);
    }

    /**
     * @param  array{search: string, category: string, min_price: string, max_price: string, availability: string, sort: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedProducts(Branch $branch, array $filters, ProductCardPresenter $cards): LengthAwarePaginator
    {
        $paginator = ProductCardPresenter::withCardRelations(
            StorefrontCatalogQuery::ordered($branch, $filters),
            $branch,
        )
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Presented as a page rather than row by row: the prices of every
        // card on the page are resolved in one pass (ADR-031).
        /** @var Collection<int, Product> $products */
        $products = $paginator->getCollection();
        $presented = $cards->cards($products);

        return $paginator->setCollection(collect($presented));
    }

    /**
     * The category the shopper entered through, with the trail above it.
     *
     * @return array{name: string, slug: string, description: string|null, path: array<int, array{name: string, slug: string}>}|null
     */
    private function categoryHeading(?Category $category): ?array
    {
        if (! $category instanceof Category) {
            return null;
        }

        return [
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'path' => $this->ancestorsOf($category),
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     */
    private function ancestorsOf(Category $category): array
    {
        return array_map(
            fn (Category $ancestor): array => ['name' => $ancestor->name, 'slug' => $ancestor->slug],
            $category->ancestors(),
        );
    }

    /**
     * The category filter's options, indented by depth the way the panel's
     * category selects are.
     *
     * @return array<int, array{value: string, label: string, depth: int}>
     */
    private function categoryOptions(): array
    {
        return array_map(
            fn (array $row): array => [
                'value' => $row['category']->slug,
                'label' => $row['category']->name,
                'depth' => $row['depth'],
            ],
            CategoryTree::of(Category::query()->active()->get())->flatten(),
        );
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    private function optionsFrom(array $values): array
    {
        $options = [];

        foreach ($values as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
