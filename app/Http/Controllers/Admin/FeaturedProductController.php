<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\SetFeaturedProducts;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Requests\Admin\UpdateFeaturedProductsRequest;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The featured products shown on the storefront's front page (P2-E8-T4).
 *
 * One screen holds the whole list: its order is the decision, so there is no
 * add button that writes one row and a move button that writes another.
 */
class FeaturedProductController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageFeatured', Product::class);

        $featured = Product::query()
            ->where('is_featured', true)
            ->orderByRaw('featured_position IS NULL')
            ->orderBy('featured_position')
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'base_price', 'lowest_price', 'is_active', 'published_at'])
            ->map(fn (Product $product): array => $this->row($product))
            ->values()
            ->all();

        return Inertia::render('admin/featured-products/index', [
            'featured' => $featured,
            'candidates' => $this->candidates(),
            'shown_on_home' => HomeController::RAIL_SIZE,
        ]);
    }

    public function update(UpdateFeaturedProductsRequest $request, SetFeaturedProducts $setFeaturedProducts): RedirectResponse
    {
        $this->authorize('manageFeatured', Product::class);

        $setFeaturedProducts->handle($request->productIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Daftar produk unggulan disimpan.']);

        return back();
    }

    /**
     * Every product that is not featured yet, for the picker.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function candidates(): array
    {
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->where('is_featured', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return $products
            ->map(fn (Product $product): array => ['value' => $product->id, 'label' => $product->name])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, slug: string, price: int|null, is_active: bool, is_published: bool}
     */
    private function row(Product $product): array
    {
        return [
            'id' => $product->getKey(),
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => $product->lowest_price,
            'is_active' => (bool) $product->is_active,
            'is_published' => $product->published_at !== null && $product->published_at->isPast(),
        ];
    }
}
