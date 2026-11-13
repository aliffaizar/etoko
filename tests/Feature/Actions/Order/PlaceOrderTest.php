<?php

use App\Actions\Order\PlaceOrder;
use App\Enums\BranchStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderStatusNotification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Turning a cart into an order (P1-E7-T5).
 *
 * The action re-checks everything the cart assumed, because a cart can sit
 * open for days: the branch, the products, the prices and the stock.
 */

function placeOrder(): PlaceOrder
{
    return app(PlaceOrder::class);
}

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create([
        'supports_delivery' => true,
        'supports_pickup' => true,
    ]);
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create(['phone' => '081200000001']);
    $this->address = Address::factory()->for($this->customer)->create(['is_default' => true]);
});

/**
 * A published product with stock at the test branch.
 */
function checkoutVariant(int $quantity = 10, ?int $basePrice = null): ProductVariant
{
    $product = Product::factory()
        ->withDefaultVariant()
        ->create($basePrice === null ? [] : ['base_price' => $basePrice]);

    /** @var ProductVariant $variant */
    $variant = $product->variants()->sole();

    Stock::factory()
        ->inWarehouse(test()->warehouse)
        ->forVariant($variant)
        ->quantity($quantity)
        ->create();

    return $variant;
}

/**
 * The customer's cart with one line in it.
 */
function cartWith(ProductVariant $variant, int $quantity = 2, ?int $snapshot = null): Cart
{
    $cart = Cart::factory()->forUser(test()->customer)->create(['branch_id' => test()->branch->getKey()]);

    CartItem::factory()->forCart($cart)->forVariant($variant)->create([
        'quantity' => $quantity,
        'unit_price_snapshot' => $snapshot ?? $variant->effectivePrice(),
    ]);

    return $cart;
}

/**
 * Check out a cart that is emptied the moment after it has been priced.
 *
 * The window HIGH-01 lives in, made deterministic: the listener fires on the
 * `SELECT` that `priceLines()` runs, deletes the lines the way the winning
 * request would have, and gets out of the way — so `lockCart()` is reached
 * with a priced basket that no longer exists.
 */
function placeOrderWithEmptiedCart(Cart $cart): Order
{
    $emptied = false;

    DB::listen(function (QueryExecuted $query) use ($cart, &$emptied): void {
        // Matched on the table name alone: MySQL quotes identifiers with
        // backticks and SQLite with double quotes, and this test has to behave
        // the same on both.
        if ($emptied || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'cart_items')) {
            return;
        }

        $emptied = true;
        DB::table('cart_items')->where('cart_id', $cart->getKey())->delete();
    });

    return placeOrder()->handle($cart, test()->branch, test()->customer, deliveryData());
}

/**
 * @return array{fulfillment_type: FulfillmentType, address_id: int|null, customer_note: string|null}
 */
function deliveryData(?string $note = null): array
{
    return [
        'fulfillment_type' => FulfillmentType::Delivery,
        'address_id' => test()->address->getKey(),
        'customer_note' => $note,
    ];
}

describe('the order it writes', function () {
    it('places an order awaiting payment at the chosen branch', function () {
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 2);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Unpaid)
            ->and($order->branch_id)->toBe($this->branch->getKey())
            ->and($order->user_id)->toBe($this->customer->getKey())
            ->and($order->order_number)->toStartWith('ORD-')
            ->and($order->placed_at)->not->toBeNull();
    });

    it('adds up the money from the lines, plus flat shipping', function () {
        config()->set('shop.flat_shipping_cost', 15_000);
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 3);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->subtotal)->toBe(300_000)
            ->and($order->shipping_cost)->toBe(15_000)
            ->and($order->tax_total)->toBe(0)
            ->and($order->grand_total)->toBe(315_000);
    });

    it('charges no shipping for a pickup, and stores no address', function () {
        config()->set('shop.flat_shipping_cost', 15_000);
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 1);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, [
            'fulfillment_type' => FulfillmentType::Pickup,
            'address_id' => null,
            'customer_note' => null,
        ]);

        expect($order->shipping_cost)->toBe(0)
            ->and($order->grand_total)->toBe(100_000)
            ->and($order->shipping_recipient)->toBeNull()
            ->and($order->shipping_address_line)->toBeNull();
    });

    it('snapshots the address rather than pointing at it', function () {
        $cart = cartWith(checkoutVariant());

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->shipping_recipient)->toBe($this->address->recipient_name)
            ->and($order->shipping_address_line)->toBe($this->address->address_line)
            ->and($order->shipping_city_name)->toBe($this->address->city_name);

        $originalRecipient = $this->address->recipient_name;
        $this->address->update(['recipient_name' => 'Nama Lain', 'address_line' => 'Jalan Lain 9']);

        expect($order->fresh()->shipping_recipient)->toBe($originalRecipient)
            ->not->toBe('Nama Lain');
    });

    it('snapshots the product name, sku and price on each line', function () {
        $variant = checkoutVariant(10, 250_000);
        $product = $variant->product;
        $cart = cartWith($variant, 2);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());
        $item = $order->items()->sole();

        expect($item->product_name)->toBe($product->name)
            ->and($item->sku)->toBe($variant->sku)
            ->and($item->unit_price)->toBe(250_000)
            ->and($item->quantity)->toBe(2)
            ->and($item->subtotal)->toBe(500_000)
            ->and($item->product_variant_id)->toBe($variant->getKey());

        $product->update(['name' => 'Nama Produk Baru', 'base_price' => 999_000]);

        expect($item->fresh()->product_name)->not->toBe('Nama Produk Baru')
            ->and($item->fresh()->unit_price)->toBe(250_000);
    });

    it('adds up the weight of every unit', function () {
        $variant = checkoutVariant();
        $variant->product->update(['weight_gram' => 500]);
        $cart = cartWith($variant, 3);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->total_weight_gram)->toBe(1_500);
    });

    it('keeps the customer note', function () {
        $cart = cartWith(checkoutVariant());

        $order = placeOrder()->handle(
            $cart,
            $this->branch,
            $this->customer,
            deliveryData('Titip di pos keamanan'),
        );

        expect($order->customer_note)->toBe('Titip di pos keamanan');
    });

    it('opens the timeline with the order coming into existence', function () {
        $cart = cartWith(checkoutVariant());

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());
        $history = $order->statusHistories()->sole();

        expect($history->from_status)->toBeNull()
            ->and($history->to_status)->toBe(OrderStatus::PendingPayment);
    });

    it('empties the cart but keeps the cart itself', function () {
        $cart = cartWith(checkoutVariant());

        placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($cart->items()->count())->toBe(0)
            ->and(Cart::whereKey($cart->getKey())->exists())->toBeTrue();
    });
});

describe('the stock it holds', function () {
    it('holds the stock without taking it off the shelf (BR-6)', function () {
        $variant = checkoutVariant(10);
        $cart = cartWith($variant, 4);

        placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        $stock = Stock::acrossBranches()->sole();

        expect($stock->quantity)->toBe(10)
            ->and($stock->reserved_quantity)->toBe(4)
            ->and($stock->available_quantity)->toBe(6);
    });

    it('writes a reservation row pointing at the order', function () {
        $variant = checkoutVariant(10);
        $cart = cartWith($variant, 4);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect(StockReservation::acrossBranches()->sole()->order_id)->toBe($order->getKey());
    });

    it('refuses the whole order when the branch cannot cover a line, leaving nothing behind', function () {
        $variant = checkoutVariant(2);
        $cart = cartWith($variant, 5);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(InsufficientStockException::class);

        expect(Order::acrossBranches()->count())->toBe(0)
            ->and(StockReservation::acrossBranches()->count())->toBe(0)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and($cart->items()->count())->toBe(1);
    });

    it('counts what another unpaid order is already holding', function () {
        $variant = checkoutVariant(5);
        $first = cartWith($variant, 4);
        placeOrder()->handle($first, $this->branch, $this->customer, deliveryData());

        $other = User::factory()->customer()->create();
        $otherAddress = Address::factory()->for($other)->create();
        $secondCart = Cart::factory()->forUser($other)->create(['branch_id' => $this->branch->getKey()]);
        CartItem::factory()->forCart($secondCart)->forVariant($variant)->create(['quantity' => 2]);

        expect(fn () => placeOrder()->handle($secondCart, $this->branch, $other, [
            'fulfillment_type' => FulfillmentType::Delivery,
            'address_id' => $otherAddress->getKey(),
            'customer_note' => null,
        ]))->toThrow(InsufficientStockException::class);

        expect(Order::acrossBranches()->count())->toBe(1);
    });
});

describe('what it refuses', function () {
    it('refuses an empty cart', function () {
        $cart = Cart::factory()->forUser($this->customer)->create();

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'Keranjang Anda kosong');
    });

    it('refuses a branch that is not trading', function () {
        $cart = cartWith(checkoutVariant());
        $this->branch->update(['status' => BranchStatus::Inactive]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'sedang tidak melayani');
    });

    it('refuses a fulfillment the branch does not offer', function () {
        $cart = cartWith(checkoutVariant());
        $this->branch->update(['supports_pickup' => false]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, [
            'fulfillment_type' => FulfillmentType::Pickup,
            'address_id' => null,
            'customer_note' => null,
        ]))->toThrow(CheckoutFailedException::class, 'tidak melayani');
    });

    it('refuses a delivery with no address chosen', function () {
        $cart = cartWith(checkoutVariant());

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, [
            'fulfillment_type' => FulfillmentType::Delivery,
            'address_id' => null,
            'customer_note' => null,
        ]))->toThrow(CheckoutFailedException::class, 'Pilih alamat pengiriman');
    });

    it('refuses another customer address, without saying it exists', function () {
        $cart = cartWith(checkoutVariant());
        $stranger = Address::factory()->for(User::factory()->customer())->create();

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, [
            'fulfillment_type' => FulfillmentType::Delivery,
            'address_id' => $stranger->getKey(),
            'customer_note' => null,
        ]))->toThrow(CheckoutFailedException::class, 'Pilih alamat pengiriman');
    });

    it('refuses a product that is no longer published', function () {
        $variant = checkoutVariant();
        $cart = cartWith($variant);
        $variant->product->update(['published_at' => null]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'tidak dapat dipesan');
    });

    it('refuses a product whose category was switched off', function () {
        $variant = checkoutVariant();
        $cart = cartWith($variant);
        Category::withoutGlobalScopes()->whereKey($variant->product->category_id)
            ->update(['is_active' => false]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'tidak dapat dipesan');
    });

    it('refuses a variant that was deactivated', function () {
        $variant = checkoutVariant();
        $cart = cartWith($variant);
        $variant->update(['is_active' => false]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'tidak dapat dipesan');
    });

    it('refuses a product the chosen branch does not stock (ADR-031)', function () {
        $variant = checkoutVariant();
        $cart = cartWith($variant);

        BranchProduct::factory()->create([
            'branch_id' => $this->branch->getKey(),
            'product_id' => $variant->product_id,
            'is_available' => false,
        ]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'tidak dapat dipesan');

        expect(Order::acrossBranches()->count())->toBe(0);
    });

    it('refuses when a price has gone up, and refreshes the cart', function () {
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 1, snapshot: 100_000);
        $variant->product->update(['base_price' => 150_000]);

        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class, 'Harga beberapa produk berubah');

        expect($cart->items()->sole()->unit_price_snapshot)->toBe(150_000)
            ->and(Order::acrossBranches()->count())->toBe(0);
    });

    it('charges the lower price without complaint when one has come down', function () {
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 1, snapshot: 100_000);
        $variant->product->update(['base_price' => 80_000]);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->items()->sole()->unit_price)->toBe(80_000)
            ->and($order->subtotal)->toBe(80_000);
    });
});

describe('one cart, one order (HIGH-01)', function () {
    it('refuses a second checkout of the same cart', function () {
        $variant = checkoutVariant(10, 179_000);
        $cart = cartWith($variant, 2);

        $first = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        // The second request is the double-clicked button: the same cart, and
        // the same model instance the controller would still be holding. Three
        // parallel requests used to produce two full-priced orders and hold
        // four units for a basket of two. Which of the two refusals answers
        // depends on the timing — the re-price finds the basket empty, or
        // `lockCart()` does — and both end the same way, with one order.
        expect(fn () => placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData()))
            ->toThrow(CheckoutFailedException::class);

        expect(Order::acrossBranches()->count())->toBe(1)
            ->and(Order::acrossBranches()->sole()->getKey())->toBe($first->getKey())
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(2)
            ->and((int) StockReservation::acrossBranches()->active()->sum('quantity'))->toBe(2);
    });

    it('refuses from inside the lock when the basket emptied after pricing', function () {
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 2);

        // Stands in for the request that lost the race: priced against a full
        // basket, then asked to write it once the winner had emptied it. This
        // is the path a plain `SELECT` would have got wrong, because this
        // transaction's snapshot still shows the deleted rows.
        expect(fn () => placeOrderWithEmptiedCart($cart))
            ->toThrow(CheckoutFailedException::class, 'sudah diproses menjadi pesanan');

        expect(Order::acrossBranches()->count())->toBe(0)
            ->and((int) Stock::acrossBranches()->sum('reserved_quantity'))->toBe(0);
    });

    it('still places the order when the cart is untouched', function () {
        $variant = checkoutVariant(10, 100_000);
        $cart = cartWith($variant, 2);

        $order = placeOrder()->handle($cart, $this->branch, $this->customer, deliveryData());

        expect($order->items()->count())->toBe(1)
            ->and($cart->items()->count())->toBe(0);
    });
});

describe('telling the customer (P2-E6-T3)', function () {
    it('tells the customer their order was received, with what to pay', function () {
        Notification::fake();

        $order = placeOrder()->handle(cartWith(checkoutVariant()), $this->branch, $this->customer, deliveryData());

        Notification::assertSentTo(
            $this->customer,
            OrderStatusNotification::class,
            function (OrderStatusNotification $notification) use ($order): bool {
                $mail = $notification->toMail($this->customer);

                return $notification->status === OrderStatus::PendingPayment
                    && $notification->order->is($order)
                    && $mail->actionText === 'Bayar sekarang'
                    && str_contains($mail->introLines[0], $order->order_number);
            },
        );
    });

    it('drops the "please pay" message once the order has already moved on', function () {
        $order = placeOrder()->handle(cartWith(checkoutVariant()), $this->branch, $this->customer, deliveryData());
        $placed = new OrderStatusNotification($order, OrderStatus::PendingPayment);

        expect($placed->shouldSend($this->customer, 'mail'))->toBeTrue();

        // A COD order is moved to `paid` seconds after it is placed (ADR-040).
        $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

        expect($placed->shouldSend($this->customer, 'mail'))->toBeFalse();
    });
});
