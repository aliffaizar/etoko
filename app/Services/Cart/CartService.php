<?php

namespace App\Services\Cart;

use App\Enums\FulfillmentType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\StockAllocator;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Services\Pricing\VoucherLine;
use App\Services\Pricing\VoucherOutcome;
use App\Services\Pricing\VoucherValidator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Resolves and mutates the visitor's shopping cart (P1-E6-T4).
 *
 * Supports both guests (token in session) and authenticated users (by user_id).
 * Guest carts are merged into the user's cart on login (T5) via
 * `App\Listeners\MergeGuestCart`.
 *
 * Token lifetime: seven days. The cart row itself carries `expires_at`; a
 * scheduled command to prune stale guest carts belongs in a future phase
 * alongside the queue workers.
 *
 * The service never reads from `BranchContext` — the cart belongs to the
 * visitor, not to a staff session (ADR-028). The branch it checks stock
 * against is the one the caller passes in, or the cart's own; a branch the
 * service went looking for itself would be a second answer to a question the
 * controller has already answered.
 *
 * Prices are resolved **without a branch** (ADR-031) — see `catalogPrice()`.
 */
final class CartService
{
    public const SESSION_KEY = 'cart_token';

    public function __construct(
        private readonly Session $session,
        private readonly EffectivePriceResolver $priceResolver,
        private readonly StockAllocator $stockAllocator,
        private readonly VoucherValidator $voucherValidator,
    ) {}

    /**
     * Find or create the cart for the current visitor.
     *
     * Authenticated users always have exactly one cart (unique index on
     * `user_id`). Guests are identified by a token in the session.
     */
    public function resolve(?User $user): Cart
    {
        if ($user instanceof User) {
            return Cart::firstOrCreate(
                ['user_id' => $user->getKey()],
                ['session_token' => null, 'expires_at' => null],
            );
        }

        $token = $this->guestToken();

        return Cart::firstOrCreate(
            ['session_token' => $token, 'user_id' => null],
            ['expires_at' => now()->addDays(7)],
        );
    }

    /**
     * Add or increase quantity for a variant.
     *
     * If the item already exists its quantity is incremented by `$quantity`.
     * The price snapshot is refreshed every time an item quantity changes so
     * the cart never shows a stale price.
     *
     * Returns false when the available stock is not enough.
     */
    public function addItem(
        Cart $cart,
        ProductVariant $variant,
        int $quantity,
        ?Branch $branch,
    ): bool {
        $variant->loadMissing('product');

        $available = $this->availableStock($variant, $branch ?? $cart->branch);
        $existingQty = $cart->items()->where('product_variant_id', $variant->getKey())->value('quantity') ?? 0;

        if ($available !== null && ($existingQty + $quantity) > $available) {
            return false;
        }

        $price = $this->catalogPrice($variant);

        $existing = $cart->items()->where('product_variant_id', $variant->getKey())->first();

        if ($existing instanceof CartItem) {
            $existing->update([
                'quantity' => $existing->quantity + $quantity,
                'unit_price_snapshot' => $price->amount,
            ]);
        } else {
            $cart->items()->create([
                'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'unit_price_snapshot' => $price->amount,
            ]);
        }

        // Keep branch in sync with the first explicit choice
        if ($cart->branch_id === null && $branch instanceof Branch) {
            $cart->update(['branch_id' => $branch->getKey()]);
        }

        return true;
    }

    /**
     * Set an item's quantity directly (replace, not increment).
     *
     * Quantity of zero removes the item.
     * Returns false when the available stock is not enough.
     */
    public function updateItem(Cart $cart, int $cartItemId, int $quantity): bool
    {
        $item = $cart->items()->findOrFail($cartItemId);

        if ($quantity <= 0) {
            $item->delete();

            return true;
        }

        $item->loadMissing('variant.product');
        $available = $this->availableStock($item->variant, $cart->branch);

        if ($available !== null && $quantity > $available) {
            return false;
        }

        $price = $this->catalogPrice($item->variant);
        $item->update(['quantity' => $quantity, 'unit_price_snapshot' => $price->amount]);

        return true;
    }

    /**
     * The price the storefront charges for a variant.
     *
     * Resolved **without a branch** (ADR-031): the website has one catalogue
     * and one price, and `branch_product_prices` is never read from it — that
     * table waits for POS. Passing the chosen branch here would let a cart
     * line snapshot a branch price while the product page that put it there
     * quoted the catalogue price, so the two have to ask the same question.
     */
    private function catalogPrice(ProductVariant $variant): ResolvedPrice
    {
        return $this->priceResolver->for($variant, null);
    }

    /**
     * What the cart's voucher does for this basket, or null when it has none
     * (P2-E5-T3).
     *
     * The cart and checkout screens ask through here, `PlaceOrder` asks the
     * validator with its own freshly priced lines — the same rules either way.
     * The lines are read from the cart's snapshots, so the items must be
     * loaded with `variant.product`.
     *
     * @param  int|null  $shippingCost  The delivery charge, when it is known.
     */
    public function voucherOutcome(
        Cart $cart,
        ?Branch $branch,
        ?User $user,
        ?FulfillmentType $fulfillment = null,
        ?int $shippingCost = null,
    ): ?VoucherOutcome {
        if ($cart->voucher_id === null) {
            return null;
        }

        $voucher = $cart->voucher;

        if ($voucher === null) {
            return null;
        }

        return $this->voucherValidator->check(
            $voucher,
            $cart->items->map(fn (CartItem $item): VoucherLine => VoucherLine::fromCartItem($item))->all(),
            $branch,
            $user,
            fulfillment: $fulfillment,
            shippingCost: $shippingCost,
        );
    }

    /**
     * Remove a single item from the cart.
     */
    public function removeItem(Cart $cart, int $cartItemId): void
    {
        $cart->items()->whereKey($cartItemId)->delete();
    }

    /**
     * Merge a guest cart into an authenticated user's cart (P1-E6-T5).
     *
     * Called by `MergeGuestCart` listener on `Login`. If the user already has
     * items, existing variants are updated to max(existing, guest) quantity
     * rather than doubled. The guest cart is deleted afterwards.
     */
    public function mergeGuestCart(User $user, string $sessionToken): void
    {
        $guestCart = Cart::where('session_token', $sessionToken)->where('user_id', null)->first();

        if (! $guestCart instanceof Cart || $guestCart->items()->count() === 0) {
            $guestCart?->delete();

            return;
        }

        $userCart = $this->resolve($user);

        foreach ($guestCart->items as $guestItem) {
            $existing = $userCart->items()
                ->where('product_variant_id', $guestItem->product_variant_id)
                ->first();

            if ($existing instanceof CartItem) {
                // Keep the higher quantity, refresh the price snapshot
                $newQty = max($existing->quantity, $guestItem->quantity);
                $existing->update([
                    'quantity' => $newQty,
                    'unit_price_snapshot' => $guestItem->unit_price_snapshot,
                ]);
            } else {
                $userCart->items()->create([
                    'product_variant_id' => $guestItem->product_variant_id,
                    'quantity' => $guestItem->quantity,
                    'unit_price_snapshot' => $guestItem->unit_price_snapshot,
                    'notes' => $guestItem->notes,
                ]);
            }
        }

        // Carry the guest's branch choice over if the user cart has none
        if ($userCart->branch_id === null && $guestCart->branch_id !== null) {
            $userCart->update(['branch_id' => $guestCart->branch_id]);
        }

        $guestCart->delete();
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * How many units are in the visitor's cart, or zero when there is none.
     * Drives the header badge.
     *
     * **Units, not lines.** Every "N item" the application prints — the cart
     * summary, the checkout summary, an order row — is
     * `Cart::totalQuantity()`, a sum of quantities. A badge that counted rows
     * instead would show "1" beside a summary reading "4 item" on the same
     * screen, which is the kind of disagreement nobody can resolve by looking.
     */
    public function itemCount(?User $user): int
    {
        if ($user instanceof User) {
            return (int) CartItem::whereHas(
                'cart',
                fn ($q) => $q->where('user_id', $user->getKey()),
            )->sum('quantity');
        }

        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token)) {
            return 0;
        }

        return (int) CartItem::whereHas(
            'cart',
            fn ($q) => $q->where('session_token', $token)->whereNull('user_id'),
        )->sum('quantity');
    }

    /**
     * Return the token that identifies this guest's cart, creating one if none
     * exists yet.
     */
    public function guestToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token)) {
            $token = Str::uuid()->toString();
            $this->session->put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Available stock (on-hand minus reserved) for a variant at a branch.
     *
     * Asked of `StockAllocator`, which is the one definition of "what this
     * branch can sell" (ADR-033) — every warehouse the branch keeps, summed,
     * read across branch scope because the branch is the customer's choice and
     * not the actor's (ADR-028).
     *
     * This used to be a query of its own ending in `->first()`, which read a
     * single `stocks` row (MED-01). In a branch with more than one warehouse —
     * supported since `P1-E2-T6` — that gave three different answers to one
     * question: the product card summed every warehouse and promised 7, the
     * cart refused at 3, and checkout would happily have served all 7. It
     * turned down sales the shop could make.
     *
     * Returns null when the branch is unknown (unbounded add allowed; checkout
     * will verify).
     */
    private function availableStock(ProductVariant $variant, ?Branch $branch): ?int
    {
        if (! $branch instanceof Branch) {
            return null;
        }

        return $this->stockAllocator->availableAtBranch($branch, $variant);
    }
}
