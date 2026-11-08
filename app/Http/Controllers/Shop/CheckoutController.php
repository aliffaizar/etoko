<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Order\PlaceOrder;
use App\Actions\Payment\InitiatePayment;
use App\Enums\FulfillmentType;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\PlaceOrderRequest;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PaymentMethod;
use App\Models\Stock;
use App\Models\User;
use App\Services\Branch\StorefrontBranch;
use App\Services\Cart\CartService;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\PaymentMethodEligibility;
use App\Support\PaymentPresenter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkout (P1-E7-T4, T5).
 *
 * One screen with three decisions on it — where it goes, how it gets there,
 * and what it costs — and one button. Signed-in customers only: an order
 * belongs to an account, and guest checkout needs an identity to send the
 * order to, which is a later phase.
 *
 * The branch comes from the storefront session (`StorefrontBranch`), never
 * from the signed-in user's `branch_id` (ADR-028). The prices quoted here are
 * catalogue prices, the same at every branch (ADR-031); what the branch
 * decides is stock and where it can be collected.
 *
 * Everything this screen shows is checked again by `PlaceOrder` inside its
 * transaction — the screen is a quotation, not a promise. A refusal comes back
 * as a validation error on the field it is about, so it lands next to the
 * control that caused it rather than in a banner at the top.
 *
 * **Four decisions since `P2-E1-T3`**, because how to pay belongs here: the
 * branch, the destination and the total are all known at checkout, and those
 * are exactly what decides whether COD is on offer (`P2-E1-T7`). The methods
 * are judged against the screen's own quotation — subtotal plus the same flat
 * shipping and tax `PlaceOrder` applies — and judged **again** by
 * `InitiatePayment` against the order that actually gets written. The second
 * check is the authoritative one; the first exists so a customer is not
 * offered COD and then refused it.
 *
 * Placing the order and asking for the money are **two steps, not one**. The
 * gateway call in `InitiatePayment` is an HTTP request to somebody else's
 * server, and running it inside `PlaceOrder`'s transaction would hold the
 * cart's and the stock's row locks for as long as Midtrans took to answer. If
 * the second step fails, the order exists and is unpaid — which is a state the
 * order page handles, because it offers the method picker anyway.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly StorefrontBranch $storefrontBranch,
        private readonly PaymentMethodEligibility $eligibility,
    ) {}

    /**
     * The checkout screen.
     *
     * A customer who arrives with an empty cart is sent back to the cart
     * rather than shown a checkout for nothing.
     */
    public function index(): Response|RedirectResponse
    {
        /** @var User $customer */
        $customer = auth()->user();
        $branch = $this->storefrontBranch->currentOrFail();
        $cart = $this->loadedCart($customer, $branch);

        if ($cart->items->isEmpty()) {
            return to_route('shop.cart.index');
        }

        $addresses = $this->addressProps($customer);

        return Inertia::render('shop/checkout', [
            'cart' => $this->cartProps($cart),
            'branch' => $this->branchProps($branch),
            'addresses' => $addresses,
            'costs' => [
                'flat_shipping_cost' => PlaceOrder::flatShippingCost(),
                'tax_percent' => PlaceOrder::taxPercent(),
            ],
            'payment_methods' => $this->paymentMethodProps($branch, $cart, $addresses),
            'reservation_minutes' => (int) config('shop.reservation_minutes'),
        ]);
    }

    /**
     * Place the order.
     *
     * The two failure modes are told apart on purpose: a business refusal
     * (`CheckoutFailedException`) names the thing to fix, while running out of
     * stock mid-transaction (`InsufficientStockException`) is somebody else
     * having bought it first — a race the customer did not lose through any
     * fault of their own, so it says so plainly (BR-6).
     */
    public function store(
        PlaceOrderRequest $request,
        PlaceOrder $placeOrder,
        InitiatePayment $initiatePayment,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();
        $branch = $this->storefrontBranch->currentOrFail();
        $cart = $this->cartService->resolve($customer);
        $method = PaymentMethod::query()->findOrFail($request->paymentMethodId());

        try {
            $order = $placeOrder->handle($cart, $branch, $customer, $request->toCheckoutData());
        } catch (CheckoutFailedException $refused) {
            return back()->withErrors([
                'checkout' => $refused->getMessage(),
                ...($refused->problems === [] ? [] : ['items' => implode(' ', $refused->problems)]),
            ]);
        } catch (InsufficientStockException $raced) {
            return back()->withErrors([
                'checkout' => 'Stok salah satu produk baru saja habis diambil pembeli lain. '
                    .'Kurangi jumlahnya di keranjang, lalu coba lagi.',
                'items' => $raced->getMessage(),
            ]);
        }

        try {
            $initiatePayment->handle($order, $method, $customer);
        } catch (PaymentFailedException $refused) {
            // The order is placed and its stock is held; only the ask for
            // money failed — a gateway that was down, or a method that stopped
            // being eligible between the screen and here. The order page is
            // where a method is chosen anyway, so the customer is sent there
            // with the reason rather than being left on a checkout whose cart
            // is now empty.
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => "Pesanan {$order->order_number} sudah dibuat, tetapi pembayarannya belum dimulai: "
                    .$refused->getMessage(),
            ]);

            return to_route('shop.account.orders.show', [$order, 'baru' => 1]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} berhasil dibuat.",
        ]);

        return to_route('shop.account.orders.show', [$order, 'baru' => 1]);
    }

    /**
     * The cart with everything the summary reads, plus the stock figure at the
     * chosen branch so a line that can no longer be covered says so here
     * rather than at the button.
     */
    private function loadedCart(User $customer, Branch $branch): Cart
    {
        $cart = $this->cartService->resolve($customer);

        $cart->load([
            'items' => fn (HasMany $q) => $q->with([
                // The whole product row, not a narrowed select: the summary
                // reads its weight through `effectiveWeightGram()`, and a
                // column left out of a select comes back null rather than
                // loudly missing.
                'variant' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'product' => fn ($q) => $q
                        ->with(['images' => fn ($q) => $q->where('is_primary', true)->limit(1)]),
                    'stocks' => fn (HasMany $q) => $q->where('branch_id', $branch->getKey()),
                ]),
            ]),
        ]);

        return $cart;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartProps(Cart $cart): array
    {
        return [
            'subtotal' => $cart->subtotal(),
            'item_count' => $cart->totalQuantity(),
            'total_weight_gram' => $cart->items->sum(
                fn (CartItem $item): int => $item->variant->effectiveWeightGram() * $item->quantity,
            ),
            'items' => $cart->items->map(fn (CartItem $item): array => [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_snapshot,
                'line_total' => $item->lineTotal(),
                'sku' => $item->variant->sku,
                'name' => $item->variant->displayName(),
                'product_name' => $item->variant->product->name,
                'product_slug' => $item->variant->product->slug,
                'image_url' => $item->variant->product->images->first()?->url(),
                'available_quantity' => (int) $item->variant->stocks->sum(
                    fn (Stock $stock): int => $stock->available_quantity,
                ),
            ])->values()->all(),
        ];
    }

    /**
     * The chosen branch, with what it will and will not do — the screen hides
     * a fulfilment the branch does not offer rather than letting it be chosen
     * and then refused.
     *
     * @return array<string, mixed>
     */
    private function branchProps(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'city_name' => $branch->city_name,
            'address_line' => $branch->address_line,
            'phone' => $branch->phone,
            'supports_delivery' => $branch->supports_delivery,
            'supports_pickup' => $branch->supports_pickup,
            'fulfillment_options' => array_values(array_filter(
                array_map(
                    fn (FulfillmentType $type): ?array => match (true) {
                        $type === FulfillmentType::Delivery && ! $branch->supports_delivery => null,
                        $type === FulfillmentType::Pickup && ! $branch->supports_pickup => null,
                        default => ['value' => $type->value, 'label' => $type->label()],
                    },
                    FulfillmentType::cases(),
                ),
            )),
        ];
    }

    /**
     * The ways this cart may be paid for, each with its verdict (P2-E1-T7).
     *
     * Judged against the **screen's own quotation** of the order: the cart's
     * subtotal plus the flat delivery charge and the tax `PlaceOrder` will
     * apply. The delivery charge is included because a method's ceiling is a
     * limit on the bill the customer sees, and quoting it against the subtotal
     * alone would offer COD for an order that then exceeded the limit.
     *
     * The destination is taken from the customer's **default** address, since
     * that is the one the screen pre-selects. Switching to an address in
     * another city re-evaluates nothing until the order is placed, and
     * `InitiatePayment` has the final word — a mismatch there is reported on
     * the order page rather than silently ignored.
     *
     * Both fulfilment types are quoted for, because the customer has not
     * chosen one yet; the screen filters by what they pick, which is why each
     * method carries its own `fulfillment_types`.
     *
     * @param  array<int, array<string, mixed>>  $addresses
     * @return array<string, mixed>
     */
    private function paymentMethodProps(Branch $branch, Cart $cart, array $addresses): array
    {
        $default = $addresses[0] ?? null;
        $methods = PaymentMethod::query()->active()->ordered()->get();

        $quote = fn (FulfillmentType $type): PaymentContext => new PaymentContext(
            branchId: $branch->getKey(),
            fulfillmentType: $type,
            amount: $this->quotedTotal($cart, $type),
            cityName: $type === FulfillmentType::Delivery && is_string($default['city_name'] ?? null)
                ? $default['city_name']
                : null,
            provinceName: $type === FulfillmentType::Delivery && is_string($default['province_name'] ?? null)
                ? $default['province_name']
                : null,
        );

        return [
            'delivery' => PaymentPresenter::methodChoices(
                $methods,
                $quote(FulfillmentType::Delivery),
                $this->eligibility,
            ),
            'pickup' => PaymentPresenter::methodChoices(
                $methods,
                $quote(FulfillmentType::Pickup),
                $this->eligibility,
            ),
        ];
    }

    /**
     * What the order would come to, the same way `PlaceOrder` works it out.
     *
     * Not a second formula: the two figures it adds — the flat delivery charge
     * and the tax percentage — are read from the same `PlaceOrder` methods the
     * action itself uses, so the quotation and the order cannot disagree about
     * anything except a price that changed in between, which `PlaceOrder`
     * refuses outright.
     */
    private function quotedTotal(Cart $cart, FulfillmentType $fulfillment): int
    {
        $subtotal = $cart->subtotal();
        $shipping = $fulfillment->requiresShipping() ? PlaceOrder::flatShippingCost() : 0;
        $tax = (int) round($subtotal * PlaceOrder::taxPercent() / 100);

        return $subtotal + $shipping + $tax;
    }

    /**
     * The customer's saved addresses, default first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addressProps(User $customer): array
    {
        return $customer->addresses()
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get()
            ->map(fn (Address $address): array => [
                'id' => $address->id,
                'label' => $address->label,
                'recipient_name' => $address->recipient_name,
                'phone' => $address->phone,
                'address_line' => $address->address_line,
                'district_name' => $address->district_name,
                'city_name' => $address->city_name,
                'province_name' => $address->province_name,
                'postal_code' => $address->postal_code,
                'is_default' => $address->is_default,
            ])
            ->values()
            ->all();
    }
}
