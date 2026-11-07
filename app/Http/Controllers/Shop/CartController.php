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
use Illuminate\Http\JsonResponse;
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
 * Actions (store/update/destroy) return JSON so the React components can
 * update the badge count without a full redirect.
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
    public function store(AddCartItemRequest $request): JsonResponse
    {
        $branch = $this->storefrontBranch->current();
        $cart = $this->cartService->resolve(auth()->user());

        $variant = ProductVariant::withoutGlobalScopes()
            ->with('product:id,name,base_price')
            ->findOrFail((int) $request->validated('product_variant_id'));

        $success = $this->cartService->addItem(
            $cart,
            $variant,
            (int) $request->validated('quantity', 1),
            $branch,
        );

        if (! $success) {
            return response()->json(['message' => 'Stok tidak mencukupi.'], 422);
        }

        return response()->json([
            'message' => 'Ditambahkan ke keranjang.',
            'item_count' => $this->cartService->itemCount(auth()->user()),
        ]);
    }

    /**
     * Change an item's quantity.
     *
     * Quantity of zero removes the item.
     */
    public function update(UpdateCartItemRequest $request, int $cartItemId): JsonResponse
    {
        $cart = $this->cartService->resolve(auth()->user());

        $success = $this->cartService->updateItem(
            $cart,
            $cartItemId,
            (int) $request->validated('quantity'),
        );

        if (! $success) {
            return response()->json(['message' => 'Stok tidak mencukupi.'], 422);
        }

        $cart->load('items');

        return response()->json([
            'item_count' => $this->cartService->itemCount(auth()->user()),
            'subtotal' => $cart->subtotal(),
        ]);
    }

    /**
     * Remove a single item.
     */
    public function destroy(int $cartItemId): JsonResponse
    {
        $cart = $this->cartService->resolve(auth()->user());

        abort_unless($cart->items()->whereKey($cartItemId)->exists(), 404);

        $this->cartService->removeItem($cart, $cartItemId);

        $cart->load('items');

        return response()->json([
            'item_count' => $this->cartService->itemCount(auth()->user()),
            'subtotal' => $cart->subtotal(),
        ]);
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
