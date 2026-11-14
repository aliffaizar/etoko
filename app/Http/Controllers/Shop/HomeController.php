<?php

namespace App\Http\Controllers\Shop;

use App\Enums\BannerPosition;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Branch\StorefrontBranch;
use App\Support\BannerPresenter;
use App\Support\ProductCardPresenter;
use App\Support\StorefrontCatalogQuery;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The storefront's front page (P1-E5-T1).
 *
 * The rails are the company's catalogue, the same at every branch (ADR-031),
 * and so are their prices; only the stock badge on each card belongs to the
 * branch the visitor is shopping from (BR-2).
 *
 * The newest-products rail is deferred. It sits below the fold, and the page
 * is the first thing a visitor sees — so the banner, the categories and the
 * featured products are sent with the document and only the rail nobody has
 * scrolled to yet waits for a second request.
 */
class HomeController extends Controller
{
    /** How many products each rail shows. */
    public const RAIL_SIZE = 8;

    public function __invoke(StorefrontBranch $storefront, ProductCardPresenter $cards): Response
    {
        $branch = $storefront->currentOrFail();

        return Inertia::render('shop/home', [
            'banners' => $this->banners($branch),
            'categories' => $this->featuredCategories(),
            'featured_products' => $cards->cards($this->featuredProducts($branch)),
            'latest_products' => Inertia::defer(
                fn (): array => $cards->cards($this->latestProducts($branch)),
            ),
        ]);
    }

    /**
     * The promotional slides on the homepage (P2-E8-T1).
     *
     * Live banners for this branch, in the order staff set. A banner for another
     * branch is not shown here even though the catalogue is company-wide: it was
     * made for that branch's shoppers.
     *
     * @return array<int, array{id: int, title: string, subtitle: string|null, image_url: string|null, mobile_image_url: string|null, link_url: string|null}>
     */
    private function banners(Branch $branch): array
    {
        return Banner::query()
            ->live()
            ->forBranch($branch)
            ->where('position', BannerPosition::Hero)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Banner $banner): array => BannerPresenter::slide($banner))
            ->values()
            ->all();
    }

    /**
     * The top-level categories, as the front page's entry points.
     *
     * Categories have no "featured" flag of their own: `sort_order` is already
     * the order the catalogue is meant to be read in, so the roots in that
     * order *are* the featured ones, and a second flag would be a second
     * answer to the same question.
     *
     * @return array<int, array{name: string, slug: string, icon: string|null, image_url: string|null, child_names: array<int, string>}>
     */
    private function featuredCategories(): array
    {
        $categories = Category::query()
            ->active()
            ->roots()
            ->ordered()
            ->with(['children' => fn ($children) => $children->active()->ordered()])
            ->get();

        return $categories
            ->map(fn (Category $category): array => [
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
                'image_url' => $category->imageUrl(),
                'child_names' => $category->children
                    ->take(4)
                    ->map(fn (Category $child): string => $child->name)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Branch  $stockBranch  Used only for the cards' stock figures.
     * @return Collection<int, Product>
     */
    private function featuredProducts(Branch $stockBranch): Collection
    {
        // Placed products first, in the order the catalogue manager set; a
        // product featured but not yet placed follows, newest first.
        return ProductCardPresenter::withCardRelations(
            StorefrontCatalogQuery::offered()->featured(),
            $stockBranch,
        )
            ->orderByRaw('products.featured_position IS NULL')
            ->orderBy('products.featured_position')
            ->orderByDesc('products.published_at')
            ->orderBy('products.id')
            ->limit(self::RAIL_SIZE)
            ->get();
    }

    /**
     * @param  Branch  $stockBranch  Used only for the cards' stock figures.
     * @return Collection<int, Product>
     */
    private function latestProducts(Branch $stockBranch): Collection
    {
        return ProductCardPresenter::withCardRelations(
            StorefrontCatalogQuery::offered(),
            $stockBranch,
        )
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit(self::RAIL_SIZE)
            ->get();
    }
}
