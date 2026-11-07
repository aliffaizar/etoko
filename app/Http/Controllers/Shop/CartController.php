<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddCartItemRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Services\Branch\StorefrontBranch;
use App\Services\Cart\CartService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shopping cart for the storefront (P1-E6-T4).
 *
 * Works for both guests and authenticated customers. The cart is identified
 * either by `user_id` (authenticated) or by a UUID token in the session
 * (guest). On login `MergeGuestCart` moves the guest lines into the user's
 * cart (T5).
 *
 * Actions (store/update/destroy) answer with `back()` because every caller is
 * an Inertia visit (`router.post`/`patch`/`delete`), and Inertia refuses a
 * plain JSON body. The re-render is what refreshes the header badge, since
 * `shop.cart_count` is a shared prop of `ShareStorefrontContext`; the outcome
 * itself is reported with a flash toast.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly StorefrontBranch $storefrontBranch,
    ) {}

    /**
     * The cart page.
     */
    public function index(): Response
    {
        $user = auth()->user();
        $cart = $this->cartService->resolve($user);
        $branch = $this->storefrontBranch->current();

        $cart->load([
            'branch:id,name',
            'items' => fn (HasMany $q) => $q->with([
                'variant' => fn ($q) => $q->withoutGlobalScopes()
                    ->with([
                        'product' => fn ($q) => $q
                            ->select('id', 'name', 'slug', 'base_price')
                            ->with(['images' => fn ($q) => $q->where('is_primary', true)->limit(1)]),
                        'stocks' => fn (HasMany $q) => $q->when(
                            $branch,
                            fn ($q) => $q->where('branch_id', $branch->getKey()),
                        ),
                    ]),
            ]),
        ]);

        return Inertia::render('shop/cart', [
            'cart' => $this->cartProps($cart, $branch),
        ]);
    }

    /**
     * Add a variant to the cart.
     */
    public function store(AddCartItemRequest $request): RedirectResponse
    {
        $branch = $this->storefrontBranch->current();
        $cart = $this->cartService->resolve(auth()->user());

        // Global scopes left on: `AddCartItemRequest` has already settled that
        // this variant is one a shopper may buy, and the soft-delete scope is
        // part of that answer (MED-02).
        $variant = ProductVariant::query()
            ->with('product:id,name,base_price')
            ->findOrFail((int) $request->validated('product_variant_id'));

        $success = $this->cartService->addItem(
            $cart,
            $variant,
            (int) $request->validated('quantity', 1),
            $branch,
        );

        if (! $success) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Stok tidak mencukupi.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ditambahkan ke keranjang.']);

        return back();
    }

    /**
     * Change an item's quantity.
     *
     * Quantity of zero removes the item.
     */
    public function update(UpdateCartItemRequest $request, int $cartItemId): RedirectResponse
    {
        $cart = $this->cartService->resolve(auth()->user());
        $quantity = (int) $request->validated('quantity');

        $success = $this->cartService->updateItem($cart, $cartItemId, $quantity);

        if (! $success) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Stok tidak mencukupi.']);

            return back();
        }

        if ($quantity === 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Item dihapus dari keranjang.']);
        }

        return back();
    }

    /**
     * Remove a single item.
     */
    public function destroy(int $cartItemId): RedirectResponse
    {
        $cart = $this->cartService->resolve(auth()->user());

        abort_unless($cart->items()->whereKey($cartItemId)->exists(), 404);

        $this->cartService->removeItem($cart, $cartItemId);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item dihapus dari keranjang.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function cartProps(Cart $cart, ?Branch $branch): array
    {
        return [
            'id' => $cart->id,
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'subtotal' => $cart->subtotal(),
            'item_count' => $cart->totalQuantity(),
            'items' => $cart->items->map(fn (CartItem $item): array => [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'unit_price_snapshot' => $item->unit_price_snapshot,
                'line_total' => $item->lineTotal(),
                'notes' => $item->notes,
                'variant' => [
                    'id' => $item->variant->id,
                    'sku' => $item->variant->sku,
                    'name' => $item->variant->displayName(),
                    'product_name' => $item->variant->product->name,
                    'product_slug' => $item->variant->product->slug,
                    'image_url' => $item->variant->product->images->first()?->url(),
                    'available_quantity' => $item->variant->stocks->sum(
                        fn (Stock $s): int => $s->quantity - $s->reserved_quantity,
                    ),
                ],
            ])->values()->all(),
        ];
    }
}
