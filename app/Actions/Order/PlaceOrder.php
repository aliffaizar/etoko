<?php

namespace App\Actions\Order;

use App\Actions\Shipping\CreateShipment;
use App\Actions\Shipping\QuoteShipping;
use App\Actions\Voucher\RedeemVoucher;
use App\Enums\BranchStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ShippingFailedException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Voucher;
use App\Scopes\BranchScope;
use App\Services\Pricing\EffectivePriceResolver;
use App\Services\Pricing\VoucherLine;
use App\Services\Pricing\VoucherOutcome;
use App\Services\Pricing\VoucherValidator;
use App\Services\Shipping\Data\ShippingQuote;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into an order (P1-E7-T5).
 *
 * One transaction, and nothing half-done: the order, its lines, its stock
 * holds and its first timeline row all land together, or none of them do. An
 * order whose stock was never held is an order that can be oversold; stock
 * held for an order that failed to save is stock nobody can give back.
 *
 * The cart is **not trusted**. Everything it assumed is asked again here,
 * because a cart can sit open for days:
 *
 * - the branch still trades, and still offers the chosen fulfilment;
 * - every product is still published and still sold at that branch
 *   (`branch_products.is_available = false` means "we do not stock this", which
 *   the storefront says on the product page and which stops here — ADR-031);
 * - every variant is still active;
 * - the price has not gone **up** since it was put in the basket. If it has,
 *   checkout refuses and refreshes the cart, so nobody is charged more than
 *   the figure they were shown. A price that has gone *down* is charged at the
 *   lower figure without comment — the customer is not worse off;
 * - the stock is still there, which `ReserveStock` settles from inside the row
 *   lock (BR-6);
 * - the cart is still the basket that was priced, which `lockCart()` settles
 *   from inside the cart's own row lock, so one cart cannot become two orders.
 *
 * Prices are resolved **without a branch** (ADR-031): the website has one
 * catalogue and one price list. `branch_product_prices` waits for POS.
 *
 * A voucher applied on the cart (`carts.voucher_id`) is checked again by
 * `VoucherValidator` against the priced lines, the chosen fulfilment and the
 * quoted delivery charge, and refused with a sentence rather than dropped:
 * the customer agreed to a total with the discount in it. Its unit of quota is
 * taken by `RedeemVoucher` inside the transaction, under the voucher's row
 * lock (P2-E5-T3, T4, ADR-064).
 *
 * Every descriptive and monetary column on the order and its lines is a
 * snapshot taken here (ADR-007). After this runs, editing the catalogue cannot
 * change what anyone was charged.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly EffectivePriceResolver $priceResolver,
        private readonly ReserveStock $reserveStock,
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly QuoteShipping $quoteShipping,
        private readonly CreateShipment $createShipment,
        private readonly VoucherValidator $voucherValidator,
        private readonly RedeemVoucher $redeemVoucher,
    ) {}

    /**
     * Place the cart as an order at the chosen branch.
     *
     * @param  array{fulfillment_type: FulfillmentType, address_id?: int|null, courier_service_id?: int|null, customer_note?: string|null}  $data
     * @param  User|null  $customer  The account the order belongs to; null for a guest.
     * @param  User|null  $actor  Who pressed the button, when that is not the customer (POS, admin).
     *
     * @throws CheckoutFailedException
     * @throws InsufficientStockException
     * @throws ShippingFailedException
     */
    public function handle(
        Cart $cart,
        Branch $branch,
        ?User $customer,
        array $data,
        ?User $actor = null,
    ): Order {
        $fulfillment = $data['fulfillment_type'];

        $this->assertBranchCanTake($branch, $fulfillment);
        $address = $this->resolveAddress($customer, $fulfillment, $data['address_id'] ?? null);

        // Checked and priced **before** the transaction opens, for one reason:
        // a refusal here rewrites the cart's stale price snapshots, and a
        // refusal thrown from inside the transaction would roll that back —
        // leaving the customer looking at the same wrong total they were just
        // told about.
        $lines = $this->priceLines($cart, $branch);

        // Quoted outside the transaction too, and for a sharper reason: a
        // courier driver may be an HTTP call to an aggregator (`P2-E2-T6`),
        // and running that inside the transaction would hold the cart's and
        // the stock's row locks for as long as somebody else's server took to
        // answer — the same mistake `InitiatePayment` was built to avoid.
        $quote = $this->quoteDelivery($branch, $address, $fulfillment, $data['courier_service_id'] ?? null, $lines);

        // Outside the transaction for the same reason as the prices: it is a
        // refusal with a sentence, and it needs the delivery charge, which is
        // only known once the courier has been quoted.
        $voucher = $this->checkVoucher($cart, $branch, $customer, $fulfillment, $lines, $quote);

        // Taken before the transaction opens (MED-06). `DocumentNumber` runs a
        // transaction of its own, which nested inside this one would be a mere
        // savepoint — the counter's row lock would then be held for the whole
        // length of the checkout, and every checkout in the shop would queue
        // behind that single row. A number burned by a checkout that fails
        // afterwards costs nothing.
        $orderNumber = DocumentNumber::next(DocumentNumber::PREFIX_ORDER);

        return DB::transaction(function () use ($cart, $branch, $customer, $actor, $fulfillment, $address, $lines, $data, $orderNumber, $quote, $voucher): Order {
            $this->lockCart($cart, count($lines), $voucher?->voucher);

            // Claimed before the order row is written: the order's foreign key
            // to the voucher takes a shared lock on it, and claiming after that
            // deadlocks simultaneous checkouts (P2-E5-T4, see RedeemVoucher).
            $claimed = $voucher instanceof VoucherOutcome
                ? ['voucher' => $this->redeemVoucher->claim($voucher->voucher, $customer), 'discount' => $voucher->totalDiscount()]
                : null;

            $order = $this->createOrder($orderNumber, $branch, $customer, $actor, $fulfillment, $address, $lines, $data, $quote, $voucher);

            $this->createItems($order, $lines, $voucher);

            // The courier the customer chose, recorded as part of the order:
            // `orders.shipping_cost` and the row that explains where that
            // figure came from have to land together, or the order carries a
            // delivery charge nobody can account for.
            if ($quote instanceof ShippingQuote) {
                $this->createShipment->handle($order, $quote);
            }

            if ($claimed !== null) {
                $this->redeemVoucher->record($claimed['voucher'], $order, $customer, $claimed['discount']);
            }

            $this->reserveStock->handle(
                $order,
                array_map(
                    fn (array $line): array => ['variant' => $line['variant'], 'quantity' => $line['quantity']],
                    $lines,
                ),
            );

            $this->transitionOrderStatus->recordInitial($order, $actor, 'Pesanan dibuat.');

            $cart->items()->delete();

            if ($cart->voucher_id !== null) {
                $cart->forceFill(['voucher_id' => null])->save();
            }

            return $order;
        });
    }

    /**
     * Take the cart's row lock and refuse a basket that is no longer the one
     * that was priced (HIGH-01).
     *
     * Without this, one cart could become two orders: a double-clicked button
     * sends two requests, both read the same full cart outside any lock, and
     * both run to completion — two orders at the full total, and twice the
     * stock held for goods bought once. Three parallel requests on one cart
     * reproduced it exactly: two orders of Rp358.000 each and four units held
     * for a basket of two.
     *
     * Both reads here are **locking** reads, and that is not decoration: a
     * plain `SELECT` would answer from the snapshot this transaction opened
     * with, which still shows the items the winning request has since deleted.
     * A locking read always sees the latest committed state, so the second
     * request finds the empty cart the first one left behind.
     *
     * The line count has to match as well, because the prices and totals were
     * settled before this lock was taken: a basket changed in another tab in
     * that window would otherwise be charged as it was, not as it is. Both
     * refusals roll the transaction back with nothing written.
     *
     * The voucher is held to the same standard: a voucher removed or swapped
     * in another tab after the totals were worked out would otherwise be
     * charged as it was.
     *
     * @param  int  $pricedLines  How many lines `priceLines()` settled on.
     * @param  Voucher|null  $pricedVoucher  The voucher the totals were worked out with.
     *
     * @throws CheckoutFailedException
     */
    private function lockCart(Cart $cart, int $pricedLines, ?Voucher $pricedVoucher): void
    {
        $locked = Cart::whereKey($cart->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Cart) {
            throw CheckoutFailedException::cartAlreadyPlaced();
        }

        if ($locked->items()->lockForUpdate()->count() !== $pricedLines) {
            throw CheckoutFailedException::cartAlreadyPlaced();
        }

        if ($locked->voucher_id !== $pricedVoucher?->getKey()) {
            throw CheckoutFailedException::voucherRejected(
                'Voucher di keranjang baru saja berubah. Periksa kembali totalnya, lalu buat pesanan lagi.',
            );
        }
    }

    /**
     * The cart's voucher, checked against the order as it will be written, or
     * null when the cart has none (P2-E5-T3).
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>  $lines
     *
     * @throws CheckoutFailedException
     */
    private function checkVoucher(
        Cart $cart,
        Branch $branch,
        ?User $customer,
        FulfillmentType $fulfillment,
        array $lines,
        ?ShippingQuote $quote,
    ): ?VoucherOutcome {
        if ($cart->voucher_id === null) {
            return null;
        }

        $voucher = Voucher::query()->find($cart->voucher_id);

        if (! $voucher instanceof Voucher) {
            return null;
        }

        $outcome = $this->voucherValidator->check(
            $voucher,
            array_map(fn (array $line): VoucherLine => VoucherLine::fromVariant($line['variant'], $line['subtotal']), $lines),
            $branch,
            $customer,
            OrderChannel::Online,
            $fulfillment,
            $this->shippingCostFor($fulfillment, $quote),
        );

        if (! $outcome->isAccepted()) {
            throw CheckoutFailedException::voucherRejected((string) $outcome->rejection);
        }

        return $outcome;
    }

    /**
     * Refuse a branch that cannot take the order at all.
     *
     * Checked before the transaction opens: there is nothing to roll back, and
     * the message is about the whole order rather than a line of it.
     *
     * @throws CheckoutFailedException
     */
    private function assertBranchCanTake(Branch $branch, FulfillmentType $fulfillment): void
    {
        if ($branch->status !== BranchStatus::Active) {
            throw CheckoutFailedException::branchNotTrading($branch);
        }

        $supported = match ($fulfillment) {
            FulfillmentType::Delivery => $branch->supports_delivery,
            FulfillmentType::Pickup => $branch->supports_pickup,
        };

        if (! $supported) {
            throw CheckoutFailedException::fulfillmentUnsupported($branch, mb_strtolower($fulfillment->label()));
        }
    }

    /**
     * The address a delivery order ships to, or null for a pickup.
     *
     * A guest has no saved addresses, so delivery is not offered to one yet —
     * guest checkout with a typed-in address belongs with guest orders in a
     * later phase. The address must belong to the customer: looking it up
     * through the relation rather than by id is what makes somebody else's
     * address unreachable rather than merely unauthorised.
     *
     * @throws CheckoutFailedException
     */
    private function resolveAddress(?User $customer, FulfillmentType $fulfillment, ?int $addressId): ?Address
    {
        if ($fulfillment === FulfillmentType::Pickup) {
            return null;
        }

        if (! $customer instanceof User || $addressId === null) {
            throw CheckoutFailedException::addressRequired();
        }

        $address = $customer->addresses()->whereKey($addressId)->first();

        if (! $address instanceof Address) {
            throw CheckoutFailedException::addressRequired();
        }

        return $address;
    }

    /**
     * The courier quote this order's delivery charge comes from, or null when
     * there is none to make (P2-E2-T3).
     *
     * **The browser's number is never trusted** (ADR-042). The checkout form
     * sends back a `courier_service_id` and nothing else; the cost is worked out here,
     * against the address the order is really going to and the weight the
     * basket really is. Accepting a figure from the client would let anybody
     * ship a wardrobe for a rupiah.
     *
     * Null in two cases, and they are not the same:
     *
     * - **A pickup.** Nothing is being sent, so there is no charge and no
     *   parcel.
     * - **A delivery with no service named.** That is a POS sale or an
     *   admin-entered order whose delivery the shop arranges by hand, and the
     *   flat charge in `config/shop.php` is what it bills for it. The
     *   storefront never takes this path: `PlaceOrderRequest` insists on a
     *   service for a delivery, because a shopper who was never asked which
     *   courier to use has not finished checking out. An order that lands here
     *   gets no `shipments` row, and `DispatchShipment` opens one when the
     *   branch says which courier it actually used.
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>  $lines
     *
     * @throws ShippingFailedException
     */
    private function quoteDelivery(
        Branch $branch,
        ?Address $address,
        FulfillmentType $fulfillment,
        ?int $courierServiceId,
        array $lines,
    ): ?ShippingQuote {
        if (! $fulfillment->requiresShipping() || $courierServiceId === null) {
            return null;
        }

        if (! $address instanceof Address) {
            throw ShippingFailedException::destinationMissing();
        }

        $service = CourierService::query()->with('courier')->find($courierServiceId);

        if (! $service instanceof CourierService) {
            throw ShippingFailedException::destinationMissing();
        }

        return $this->quoteShipping->forService(
            $service,
            ShippingQuoteRequest::forAddress(
                origin: $branch,
                address: $address,
                weightGram: self::weightOf($lines),
                itemValue: array_sum(array_column($lines, 'subtotal')),
            ),
        );
    }

    /**
     * Re-check and re-price every line of the cart.
     *
     * @return array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>
     *
     * @throws CheckoutFailedException
     */
    private function priceLines(Cart $cart, Branch $branch): array
    {
        $items = $cart->items()
            ->with(['variant' => fn ($q) => $q->withoutGlobalScopes()->with([
                'product' => fn ($q) => $q->with([
                    'category',
                    'images' => fn ($q) => $q->where('is_primary', true)->limit(1),
                ]),
            ])])
            ->get();

        if ($items->isEmpty()) {
            throw CheckoutFailedException::emptyCart();
        }

        $unavailable = [];
        $repriced = [];
        $lines = [];

        $notStocked = $this->productsNotStockedAt($branch, $items);

        foreach ($items as $item) {
            $variant = $item->variant;

            if ($reason = $this->unavailabilityReason($variant, $notStocked)) {
                $unavailable[] = $reason;

                continue;
            }

            $price = $this->priceResolver->for($variant, null)->amount;

            if ($price > $item->unit_price_snapshot) {
                $item->update(['unit_price_snapshot' => $price]);
                $repriced[] = $variant->product->name;

                continue;
            }

            $lines[] = [
                'variant' => $variant,
                'quantity' => $item->quantity,
                'unit_price' => $price,
                'subtotal' => $price * $item->quantity,
                'weight_gram' => $variant->effectiveWeightGram(),
            ];
        }

        if ($unavailable !== []) {
            throw CheckoutFailedException::itemsUnavailable($unavailable);
        }

        if ($repriced !== []) {
            throw CheckoutFailedException::pricesChanged($repriced);
        }

        return $lines;
    }

    /**
     * Why a line cannot be ordered, or null when it can.
     *
     * `$variant` is never absent: `cart_items.product_variant_id` is NOT NULL
     * with `cascadeOnDelete`, so a line outlives its variant only while the
     * variant is soft deleted — and the eager load in `priceLines()` drops
     * every scope, including the soft-delete one.
     *
     * @param  array<int, true>  $notStocked  Product ids the branch has switched off, keyed by id.
     */
    private function unavailabilityReason(ProductVariant $variant, array $notStocked): ?string
    {
        $product = $variant->product;
        $name = $product->name;

        // `products.category_id` is NOT NULL, so there is always a category to
        // ask; switching one off switches its whole subtree off (ADR-019), so
        // the product's own category is the only one worth checking.
        if (! $product->isPublished() || ! $product->category->is_active) {
            return "{$name} sudah tidak dijual.";
        }

        if (! $variant->is_active) {
            return "Varian \"{$variant->displayName()}\" dari {$name} sudah tidak dijual.";
        }

        if (isset($notStocked[$product->getKey()])) {
            return "{$name} tidak dijual di cabang yang Anda pilih.";
        }

        return null;
    }

    /**
     * The cart's products that the chosen branch has switched off.
     *
     * Only deviations are stored, so no row means the branch sells it
     * (`BranchProduct::availabilityFor()`); one query answers for the whole
     * cart. Read across branches because the branch is the customer's choice,
     * not the actor's (ADR-028) — a customer has no branch context at all.
     *
     * @param  Collection<int, CartItem>  $items
     * @return array<int, true>
     */
    private function productsNotStockedAt(Branch $branch, Collection $items): array
    {
        $productIds = $items
            ->map(fn (CartItem $item): int => $item->variant->product_id)
            ->unique()
            ->all();

        if ($productIds === []) {
            return [];
        }

        $switchedOff = BranchProduct::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->getKey())
            ->whereIn('product_id', $productIds)
            ->where('is_available', false)
            ->pluck('product_id')
            ->all();

        return array_fill_keys($switchedOff, true);
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>  $lines
     * @param  array{fulfillment_type: FulfillmentType, address_id?: int|null, courier_service_id?: int|null, customer_note?: string|null}  $data
     */
    private function createOrder(
        string $orderNumber,
        Branch $branch,
        ?User $customer,
        ?User $actor,
        FulfillmentType $fulfillment,
        ?Address $address,
        array $lines,
        array $data,
        ?ShippingQuote $quote = null,
        ?VoucherOutcome $voucher = null,
    ): Order {
        $subtotal = array_sum(array_column($lines, 'subtotal'));
        $weight = self::weightOf($lines);

        $shipping = $this->shippingCostFor($fulfillment, $quote);
        $goodsDiscount = $voucher->goodsDiscount ?? 0;
        $shippingDiscount = $voucher->shippingDiscount ?? 0;
        $tax = self::taxOn($subtotal - $goodsDiscount);

        return Order::create([
            'order_number' => $orderNumber,
            'user_id' => $customer?->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => OrderChannel::Online,
            'fulfillment_type' => $fulfillment,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Unpaid,
            ...$this->customerSnapshot($customer, $address),
            ...$this->shippingSnapshot($address),
            'subtotal' => $subtotal,
            'discount_total' => $goodsDiscount + $shippingDiscount,
            'shipping_cost' => $shipping,
            'tax_total' => $tax,
            'grand_total' => $subtotal - $goodsDiscount + $shipping - $shippingDiscount + $tax,
            'total_weight_gram' => $weight,
            'voucher_id' => $voucher?->voucher->getKey(),
            'voucher_code' => $voucher?->voucher->code,
            'customer_note' => $data['customer_note'] ?? null,
            'placed_at' => now(),
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Who the order is for, as they were at checkout.
     *
     * An account's own details come first; a guest order (POS, Phase 3) has
     * only what the delivery note says, and failing both there is still a
     * person to address, so the fallback is a word rather than an empty
     * column.
     *
     * @return array<string, string|null>
     */
    private function customerSnapshot(?User $customer, ?Address $address): array
    {
        if ($customer instanceof User) {
            return [
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone ?? $address?->phone,
            ];
        }

        return [
            'customer_name' => $address instanceof Address ? $address->recipient_name : 'Pelanggan',
            'customer_email' => null,
            'customer_phone' => $address?->phone,
        ];
    }

    /**
     * The shipping address, copied column by column.
     *
     * A pickup order gets nulls throughout rather than the customer's home
     * address: nothing is being shipped, and an address on the order would read
     * as a delivery that never happened.
     *
     * @return array<string, mixed>
     */
    private function shippingSnapshot(?Address $address): array
    {
        if (! $address instanceof Address) {
            return [];
        }

        return [
            'shipping_recipient' => $address->recipient_name,
            'shipping_phone' => $address->phone,
            'shipping_address_line' => $address->address_line,
            'shipping_district_name' => $address->district_name,
            'shipping_city_name' => $address->city_name,
            'shipping_province_name' => $address->province_name,
            'shipping_postal_code' => $address->postal_code,
            'shipping_latitude' => $address->latitude,
            'shipping_longitude' => $address->longitude,
        ];
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>  $lines
     */
    private function createItems(Order $order, array $lines, ?VoucherOutcome $voucher = null): void
    {
        foreach ($lines as $key => $line) {
            $variant = $line['variant'];
            $product = $variant->product;

            // The goods discount, shared across the covered lines, so a
            // returned unit is refunded at what was actually paid for it
            // (`ReturnItem::unitValue()` reads the line's net subtotal).
            $discount = $voucher?->lineDiscount($key) ?? 0;

            $order->items()->create([
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'sku' => $variant->sku,
                'product_name' => $product->name,
                'variant_name' => $variant->name,
                'image_path' => $product->images->first()?->path,
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'discount_amount' => $discount,
                'subtotal' => $line['subtotal'] - $discount,
                'weight_gram' => $line['weight_gram'],
                'cost_price' => null,
            ]);
        }
    }

    /**
     * What this order is charged for delivery.
     *
     * Three answers, in the order they are decided: a pickup pays nothing
     * because nothing is sent, a chosen courier service pays what it quoted,
     * and a delivery arranged by hand pays the flat charge.
     */
    private function shippingCostFor(FulfillmentType $fulfillment, ?ShippingQuote $quote): int
    {
        if (! $fulfillment->requiresShipping()) {
            return 0;
        }

        return $quote instanceof ShippingQuote ? $quote->cost : self::flatShippingCost();
    }

    /**
     * The whole basket's weight in grams.
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int, unit_price: int, subtotal: int, weight_gram: int}>  $lines
     */
    private static function weightOf(array $lines): int
    {
        return array_sum(array_map(
            fn (array $line): int => $line['weight_gram'] * $line['quantity'],
            $lines,
        ));
    }

    /**
     * The flat delivery charge, for a delivery with no courier chosen.
     *
     * Still here after `P2-E2-T3`, and no longer the usual case: the
     * storefront always names a courier service, so this covers the orders
     * whose delivery the shop arranges itself — a POS sale taken at the
     * counter, an order typed in by an admin. Deliberately one number rather
     * than a guess at a rate: a made-up table reads as real and would be
     * trusted.
     */
    public static function flatShippingCost(): int
    {
        return (int) config('shop.flat_shipping_cost', 0);
    }

    public static function taxPercent(): int
    {
        return (int) config('shop.tax_percent', 0);
    }

    /**
     * The tax on goods worth `$taxable` — the subtotal **after** the goods
     * discount, since tax is owed on what the goods were sold for. A free
     * delivery does not change it: delivery is not taxed here at all.
     */
    public static function taxOn(int $taxable): int
    {
        return (int) round(max($taxable, 0) * self::taxPercent() / 100);
    }
}
