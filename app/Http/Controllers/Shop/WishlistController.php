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
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer wishlist (P1-E6-T6).
 *
 * Toggle: one POST that adds or removes. It answers with `back()` rather than
 * JSON, because the heart button is an Inertia visit and Inertia refuses a
 * plain JSON body; the outcome is reported with a flash toast.
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

        // A product unpublished since it was saved is dropped by narrowing the
        // **wishlist rows**, not by narrowing the eager load and filtering the
        // nulls afterwards: a constrained eager load leaves `$w->product` null
        // on a row whose `product_id` is NOT NULL, which every later line then
        // has to remember. The row itself is never deleted — the product may
        // come back.
        $wishlisted = $user->wishlists()
            ->whereIn('product_id', $offeredProductIds)
            ->with(['product' => fn ($q) => $q
                ->with([
                    'images' => fn ($q) => $q->where('is_primary', true)->limit(1),
                    'variants' => fn ($q) => $q->active()->orderByDesc('is_default')
                        ->with([
                            'stocks' => fn ($q) => $q->when($branch, fn ($q) => $q->where('branch_id', $branch->getKey())),
                        ]),
                ]),
            ])
            ->get();

        $allVariants = new Collection(
            $wishlisted->flatMap(fn (Wishlist $w) => $w->product->variants)->all()
        );
        $resolved = $this->prices->forMany($allVariants, null);

        return Inertia::render('shop/wishlist', [
            'items' => $wishlisted
                ->map(fn (Wishlist $w) => $this->wishlistItemProps($w, $resolved))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Add or remove a product from the wishlist (toggle).
     */
    public function toggle(int $productId): RedirectResponse
    {
        $product = Product::findOrFail($productId);
        $user = auth()->user();

        $existing = $user->wishlists()->where('product_id', $productId)->first();

        if ($existing instanceof Wishlist) {
            $existing->delete();
            $message = "{$product->name} dihapus dari wishlist.";
        } else {
            $user->wishlists()->create(['product_id' => $product->getKey()]);
            $message = "{$product->name} ditambahkan ke wishlist.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
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
