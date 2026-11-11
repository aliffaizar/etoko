<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Order\PlaceOrder;
use App\Actions\Payment\InitiatePayment;
use App\Actions\Shipping\QuoteShipping;
use App\Enums\FulfillmentType;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentFailedException;
use App\Exceptions\ShippingFailedException;
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
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Support\PaymentPresenter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly QuoteShipping $quoteShipping,
    ) {}

    /**
     * The checkout screen.
     *
     * A customer who arrives with an empty cart is sent back to the cart
     * rather than shown a checkout for nothing.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $customer */
        $customer = auth()->user();
        $branch = $this->storefrontBranch->currentOrFail();
        $cart = $this->loadedCart($customer, $branch);

        if ($cart->items->isEmpty()) {
            return to_route('shop.cart.index');
        }

        $addresses = $this->addressProps($customer);
        $destination = $this->destinationFor($customer, $request->integer('alamat') ?: null, $addresses);
        $shipping = $this->shippingProps($branch, $cart, $destination);

        return Inertia::render('shop/checkout', [
            'cart' => $this->cartProps($cart),
            'branch' => $this->branchProps($branch),
            'addresses' => $addresses,
            'costs' => [
                'flat_shipping_cost' => PlaceOrder::flatShippingCost(),
                'tax_percent' => PlaceOrder::taxPercent(),
            ],
            // Quoted for the address the screen is looking at, which the
            // customer can change: switching address reloads this prop and
            // this prop only (`only: ['shipping', 'payment_methods']`), because
            // a courier's price depends on where the parcel is going and
            // nothing else on the screen does.
            'shipping' => $shipping,
            'payment_methods' => $this->paymentMethodProps($branch, $cart, $destination, $shipping),
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
        } catch (ShippingFailedException $undeliverable) {
            // The courier chosen on the screen cannot carry this parcel: it was
            // switched off since the page was drawn, or the rate reaching this
            // address has gone. Reported on the courier field rather than in a
            // banner, so it lands next to the control the customer has to
            // change — and no order is written, because the delivery charge is
            // part of the total they agreed to.
            return back()->withErrors(['courier_service_id' => $undeliverable->getMessage()]);
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
     * The address the screen quotes couriers against (P2-E2-T3).
     *
     * The one the customer picked if they picked one, and the default
     * otherwise — the same address the screen pre-selects, so the figures
     * beside the radio buttons belong to the row that is checked. Looked up
     * **through the relation**, so another customer's address id in the query
     * string selects nothing rather than quoting a stranger's city.
     *
     * @param  array<int, array<string, mixed>>  $addresses
     */
    private function destinationFor(User $customer, ?int $addressId, array $addresses): ?Address
    {
        $defaultId = $addresses[0]['id'] ?? null;
        $wanted = $addressId ?? (is_int($defaultId) ? $defaultId : null);

        if ($wanted === null) {
            return null;
        }

        return $customer->addresses()->whereKey($wanted)->first();
    }

    /**
     * The ways this cart can be sent, and what each costs (P2-E2-T3).
     *
     * Quoted server-side, always: the client is told the prices but never
     * asked for them, and `PlaceOrder` re-quotes the one that comes back.
     *
     * Three shapes of answer, and the screen needs to tell them apart:
     *
     * - **Options.** The normal case. Cheapest first, which is the order a
     *   shopper compares them in.
     * - **Nothing, with a reason.** Nobody serves the address, or the shop has
     *   no rate for it. The sentence is the server's and it tells the customer
     *   what to do instead — collect at the branch, or use another address.
     * - **Nothing, with no address yet.** A customer with no saved address has
     *   nothing to quote against, and that is not a failure to report.
     *
     * A provider outage is reported as a reason rather than swallowed
     * (`QuoteShipping` throws only when *every* provider failed), because an
     * outage that reads as "we do not deliver to you" is a lie the customer
     * would believe.
     *
     * @return array<string, mixed>
     */
    private function shippingProps(Branch $branch, Cart $cart, ?Address $destination): array
    {
        $base = [
            'address_id' => $destination?->getKey(),
            'options' => [],
            'unavailable_reason' => null,
        ];

        if (! $destination instanceof Address) {
            return $base;
        }

        $request = ShippingQuoteRequest::forAddress(
            origin: $branch,
            address: $destination,
            weightGram: $this->cartWeight($cart),
            itemValue: $cart->subtotal(),
        );

        try {
            $quotes = $this->quoteShipping->handle($request);
        } catch (ShippingFailedException $refused) {
            return [...$base, 'unavailable_reason' => $refused->getMessage()];
        }

        if ($quotes === []) {
            return [
                ...$base,
                'unavailable_reason' => ShippingFailedException::noServiceAvailable($request)->getMessage(),
            ];
        }

        return [
            ...$base,
            'options' => array_map(fn (ShippingQuote $quote): array => $quote->toArray(), $quotes),
        ];
    }

    /**
     * The whole basket's weight, the same way `PlaceOrder` adds it up.
     */
    private function cartWeight(Cart $cart): int
    {
        return $cart->items->sum(
            fn (CartItem $item): int => $item->variant->effectiveWeightGram() * $item->quantity,
        );
    }

    /**
     * The ways this cart may be paid for, each with its verdict (P2-E1-T7).
     *
     * Judged against the **screen's own quotation** of the order: the cart's
     * subtotal plus the delivery charge and the tax `PlaceOrder` will apply.
     * The delivery charge is included because a method's ceiling is a limit on
     * the bill the customer sees, and quoting it against the subtotal alone
     * would offer COD for an order that then exceeded the limit.
     *
     * Since `P2-E2-T3` the delivery charge is the **cheapest courier quote**
     * for the address the screen is looking at, not a flat figure. The
     * cheapest rather than the selected one on purpose: this prop is computed
     * before the customer has picked a courier, and judging COD against the
     * most expensive option would withhold it from an order that qualifies.
     * Picking a dearer courier can carry a borderline order over a method's
     * ceiling, and `InitiatePayment` is the one that refuses it then — which is
     * why that refusal lands on the order page as a sentence rather than being
     * swallowed.
     *
     * The destination is the address the quote was made against, so the city
     * fence on a method and the price beside the couriers always describe the
     * same place.
     *
     * Both fulfilment types are quoted for, because the customer has not
     * chosen one yet; the screen filters by what they pick, which is why each
     * method carries its own `fulfillment_types`.
     *
     * @param  Address|null  $destination  The address the couriers were quoted against.
     * @param  array<string, mixed>  $shipping  The output of `shippingProps()`.
     * @return array<string, mixed>
     */
    private function paymentMethodProps(Branch $branch, Cart $cart, ?Address $destination, array $shipping): array
    {
        $methods = PaymentMethod::query()->active()->ordered()->get();
        $cheapestShipping = $this->cheapestShippingCost($shipping);

        $quote = fn (FulfillmentType $type): PaymentContext => new PaymentContext(
            branchId: $branch->getKey(),
            fulfillmentType: $type,
            amount: $this->quotedTotal($cart, $type, $cheapestShipping),
            cityName: $type === FulfillmentType::Delivery ? $destination?->city_name : null,
            provinceName: $type === FulfillmentType::Delivery ? $destination?->province_name : null,
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
     * The cheapest delivery charge on offer, or the flat one when none is.
     *
     * The fallback matters: an address nobody serves still has to produce a
     * figure for the payment methods to be judged against, and the flat charge
     * is what `PlaceOrder` would bill if such an order were placed by hand.
     *
     * @param  array<string, mixed>  $shipping
     */
    private function cheapestShippingCost(array $shipping): int
    {
        $options = is_array($shipping['options'] ?? null) ? $shipping['options'] : [];

        if ($options === []) {
            return PlaceOrder::flatShippingCost();
        }

        return (int) min(array_map(
            static fn (array $option): int => (int) $option['cost'],
            $options,
        ));
    }

    /**
     * What the order would come to, the same way `PlaceOrder` works it out.
     *
     * Not a second formula: the delivery charge is a courier quote this
     * controller asked `QuoteShipping` for — the same action `PlaceOrder`
     * re-asks — and the tax percentage is read from the same `PlaceOrder`
     * method the action itself uses. So the quotation and the order cannot
     * disagree about anything except a price that changed in between, which
     * `PlaceOrder` refuses outright.
     */
    private function quotedTotal(Cart $cart, FulfillmentType $fulfillment, int $shippingCost): int
    {
        $subtotal = $cart->subtotal();
        $shipping = $fulfillment->requiresShipping() ? $shippingCost : 0;
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
