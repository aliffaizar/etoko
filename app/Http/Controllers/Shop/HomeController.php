<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Branch\StorefrontBranch;
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
    private const RAIL_SIZE = 8;

    public function __invoke(StorefrontBranch $storefront, ProductCardPresenter $cards): Response
    {
        $branch = $storefront->currentOrFail();

        return Inertia::render('shop/home', [
            'banners' => $this->banners(),
            'categories' => $this->featuredCategories(),
            'featured_products' => $cards->cards($this->featuredProducts($branch)),
            'latest_products' => Inertia::defer(
                fn (): array => $cards->cards($this->latestProducts($branch)),
            ),
        ]);
    }

    /**
     * The promotional slides.
     *
     * Static for now, by design: banners are managed content behind
     * `banners.manage` and arrive with the marketing epic in Phase 2
     * (docs/01-overview.md §G). Keeping them here, in the shape the page
     * already reads, means that epic replaces one method with a query rather
     * than rebuilding the component.
     *
     * @return array<int, array{title: string, subtitle: string, cta_label: string, tone: string}>
     */
    private function banners(): array
    {
        return [
            [
                'title' => 'Satu katalog, satu harga',
                'subtitle' => 'Semua produk tersedia di seluruh cabang dengan harga yang sama.',
                'cta_label' => 'Lihat katalog',
                'tone' => 'primary',
            ],
            [
                'title' => 'Ambil sendiri di cabang',
                'subtitle' => 'Pesan online, ambil hari yang sama di cabang yang mendukung pickup.',
                'cta_label' => 'Mulai belanja',
                'tone' => 'accent',
            ],
            [
                'title' => 'Stok nyata, bukan perkiraan',
                'subtitle' => 'Pilih cabang untuk melihat apa yang bisa kamu bawa pulang hari ini.',
                'cta_label' => 'Telusuri produk',
                'tone' => 'muted',
            ],
        ];
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
        return ProductCardPresenter::withCardRelations(
            StorefrontCatalogQuery::offered()->featured(),
            $stockBranch,
        )
            ->orderByDesc('published_at')
            ->orderBy('id')
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
