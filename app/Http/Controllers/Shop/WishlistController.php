<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\Branch\StorefrontBranch;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Support\StorefrontCatalogQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer wishlist (P1-E6-T6).
 *
 * Toggle: POST to add, DELETE to remove. Returns JSON for the heart button.
 * The list page is a standard Inertia render.
 *
 * "Move to cart" on the list page sends a POST to CartController::store with
 * the product's default variant — the redirect goes to the cart page.
 */
class WishlistController extends Controller
{
    public function __construct(
        private readonly StorefrontBranch $storefrontBranch,
        private readonly EffectivePriceResolver $prices,
    ) {}

    public function index(): Response
    {
        $user = auth()->user();
        $branch = $this->storefrontBranch->current();

        $offeredProductIds = StorefrontCatalogQuery::offered()->pluck('id');

        $wishlisted = $user->wishlists()
            ->with(['product' => fn ($q) => $q
                ->whereIn('products.id', $offeredProductIds)
                ->with([
                    'images' => fn ($q) => $q->where('is_primary', true)->limit(1),
                    'variants' => fn ($q) => $q->active()->orderByDesc('is_default')
                        ->with([
                            'stocks' => fn ($q) => $q->when($branch, fn ($q) => $q->where('branch_id', $branch->getKey())),
                        ]),
                ]),
            ])
            ->get();

        // Some wishlist products may have been unpublished since being saved —
        // the join via offered() excludes them from the eager load, so the
        // relation will be null for those rows. Filter them out silently.
        $valid = $wishlisted->filter(fn (Wishlist $w): bool => $w->product instanceof Product);

        $allVariants = new Collection(
            $valid->flatMap(fn (Wishlist $w) => $w->product->variants)->all()
        );
        $resolved = $this->prices->forMany($allVariants, null);

        return Inertia::render('shop/wishlist', [
            'items' => $valid->map(fn (Wishlist $w) => $this->wishlistItemProps($w, $resolved))->values()->all(),
        ]);
    }

    /**
     * Add or remove a product from the wishlist (toggle).
     */
    public function toggle(int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $user = auth()->user();

        $existing = $user->wishlists()->where('product_id', $productId)->first();

        if ($existing instanceof Wishlist) {
            $existing->delete();
            $wishlisted = false;
        } else {
            $user->wishlists()->create(['product_id' => $product->getKey()]);
            $wishlisted = true;
        }

        return response()->json([
            'wishlisted' => $wishlisted,
            'count' => $user->wishlists()->count(),
        ]);
    }

    /**
     * @param  array<int, ResolvedPrice>  $resolved
     * @return array<string, mixed>
     */
    private function wishlistItemProps(Wishlist $item, array $resolved): array
    {
        $product = $item->product;
        $defaultVariant = $product->variants->firstWhere('is_default', true)
            ?? $product->variants->first();

        $price = $defaultVariant ? ($resolved[$defaultVariant->getKey()] ?? null) : null;

        $available = $defaultVariant
            ? (int) $defaultVariant->stocks->sum(fn ($s) => $s->quantity - $s->reserved_quantity)
            : 0;

        return [
            'wishlist_id' => $item->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'image_url' => $product->images->first()?->url(),
            'price' => $price?->amount,
            'compare_at_price' => $price?->compareAtPrice,
            'is_in_stock' => $available > 0,
            'default_variant_id' => $defaultVariant?->id,
        ];
    }
}
