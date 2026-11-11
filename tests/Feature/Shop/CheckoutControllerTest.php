<?php

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Checkout (P1-E7-T4, T5).
 *
 * Signed-in customers only: an order belongs to an account, and there is
 * nowhere to send a guest's confirmation yet.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create([
        'supports_delivery' => true,
        'supports_pickup' => true,
    ]);
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->address = Address::factory()->for($this->customer)->create(['is_default' => true]);

    // Checkout asks how the order will be paid for since P2-E1-T3, so every
    // post below names a method. A plain manual transfer is the method with no
    // fence around it, which keeps these tests about checkout.
    $this->paymentMethod = PaymentMethod::factory()->create(['name' => 'Transfer Bank BCA']);

    // Checkout also asks which courier to use since P2-E2-T3, so a delivery
    // needs a service with a rate that reaches the address. One catch-all rule
    // keeps these tests about checkout rather than about rate resolution,
    // which `ManualProviderTest` covers.
    $this->courierService = CourierService::factory()->create();
    ShippingRate::factory()->forService($this->courierService)->priced(15_000)->create();
});

/**
 * The customer's cart with one stocked line in it.
 */
function checkoutCart(int $stock = 10, int $quantity = 2): ProductVariant
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000])
        ->variants()->sole();

    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity($stock)->create();

    $cart = Cart::factory()->forUser(test()->customer)->create(['branch_id' => test()->branch->getKey()]);
    CartItem::factory()->forCart($cart)->forVariant($variant)->create([
        'quantity' => $quantity,
        'unit_price_snapshot' => 100_000,
    ]);

    return $variant;
}

describe('the checkout screen', function () {
    it('renders for a signed-in customer with items', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/checkout')
                ->has('cart.items', 1)
                ->where('branch.id', $this->branch->getKey())
                ->has('addresses', 1));
    });

    it('offers only the fulfillments the branch supports', function () {
        checkoutCart();
        $this->branch->update(['supports_pickup' => false]);

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('branch.fulfillment_options', 1)
                ->where('branch.fulfillment_options.0.value', 'delivery'));
    });

    it('sends an empty cart back to the cart page', function () {
        Cart::factory()->forUser($this->customer)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertRedirect(route('shop.cart.index'));
    });

    it('turns a guest away to sign in', function () {
        $this->get(route('shop.checkout.index'))->assertRedirect(route('login'));
    });
});

describe('choosing a courier', function () {
    it('quotes every courier for the address the screen is looking at', function () {
        checkoutCart();
        $express = CourierService::factory()->express()->create();
        ShippingRate::factory()->forService($express)->priced(32_000)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shipping.address_id', $this->address->getKey())
                ->has('shipping.options', 2)
                // Cheapest first, which is the order a shopper compares them in.
                ->where('shipping.options.0.cost', 15_000)
                ->where('shipping.options.1.cost', 32_000)
                ->where('shipping.unavailable_reason', null)
                ->etc());
    });

    it('quotes the address named in the query string rather than the default', function () {
        checkoutCart();
        $other = Address::factory()->for($this->customer)->create(['city_name' => 'Surabaya']);

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index', ['alamat' => $other->getKey()]))
            ->assertInertia(fn ($page) => $page
                ->where('shipping.address_id', $other->getKey())
                ->etc());
    });

    it('quotes nobody else address', function () {
        checkoutCart();
        $stranger = Address::factory()->for(User::factory()->customer())->create();

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index', ['alamat' => $stranger->getKey()]))
            ->assertInertia(fn ($page) => $page
                ->where('shipping.address_id', null)
                ->where('shipping.options', [])
                ->etc());
    });

    it('explains an address nobody serves instead of showing an empty list', function () {
        checkoutCart();
        ShippingRate::query()->delete();

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertInertia(fn ($page) => $page
                ->where('shipping.options', [])
                ->where('shipping.unavailable_reason', fn (?string $reason): bool => is_string($reason)
                    && str_contains($reason, 'Belum ada layanan pengiriman'))
                ->etc());
    });

    it('prices the whole basket weight, not one item', function () {
        $variant = checkoutCart(quantity: 3);
        $variant->update(['weight_gram' => 1_000]);
        ShippingRate::query()->update(['price_per_kg' => 5_000]);

        // 3 kg: the flat price covers the first, two more at 5.000.
        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertInertia(fn ($page) => $page
                ->where('shipping.options.0.cost', 15_000 + (2 * 5_000))
                ->etc());
    });
});

describe('the delivery charge on the order', function () {
    it('charges what the chosen service quotes, not the flat figure', function () {
        $variant = checkoutCart(quantity: 1);
        $variant->update(['weight_gram' => 2_500]);
        ShippingRate::query()->update(['price_per_kg' => 6_000]);

        $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'delivery',
            'address_id' => $this->address->getKey(),
            'courier_service_id' => $this->courierService->getKey(),
            'payment_method_id' => $this->paymentMethod->getKey(),
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();

        expect($order->shipping_cost)->toBe(15_000 + (2 * 6_000))
            ->and($order->grand_total)->toBe($order->subtotal + $order->shipping_cost);
    });

    it('ignores a delivery charge the browser sends', function () {
        checkoutCart();

        $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'delivery',
            'address_id' => $this->address->getKey(),
            'courier_service_id' => $this->courierService->getKey(),
            'payment_method_id' => $this->paymentMethod->getKey(),
            // Anybody can post this. Nobody ships a wardrobe for a rupiah.
            'shipping_cost' => 1,
        ])->assertSessionHasNoErrors();

        expect(Order::query()->sole()->shipping_cost)->toBe(15_000);
    });

    it('opens the parcel the branch will send, with the courier snapshot on it', function () {
        checkoutCart();

        $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'delivery',
            'address_id' => $this->address->getKey(),
            'courier_service_id' => $this->courierService->getKey(),
            'payment_method_id' => $this->paymentMethod->getKey(),
        ])->assertSessionHasNoErrors();

        $shipment = Order::query()->sole()->activeShipment();

        expect($shipment)->not->toBeNull()
            ->and($shipment->cost)->toBe(15_000)
            ->and($shipment->courier_name)->toBe($this->courierService->courier->name)
            ->and($shipment->tracking_number)->toBeNull();
    });

    it('demands a courier for a delivery and refuses one for a pickup', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $this->address->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('courier_service_id');

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'pickup',
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('courier_service_id');

        expect(Order::query()->count())->toBe(0);
    });

    it('refuses the order when the chosen service has gone since the screen was drawn', function () {
        checkoutCart();
        $this->courierService->update(['is_active' => false]);

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $this->address->getKey(),
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('courier_service_id');

        // The delivery charge is part of the total the customer agreed to, so
        // nothing is written when it cannot be worked out.
        expect(Order::query()->count())->toBe(0);
    });

    it('charges a pickup nothing and opens no parcel', function () {
        checkoutCart();

        $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'pickup',
            'payment_method_id' => $this->paymentMethod->getKey(),
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();

        expect($order->shipping_cost)->toBe(0)
            ->and($order->shipments()->count())->toBe(0);
    });
});

describe('placing the order', function () {
    it('creates the order and sends the customer to it', function () {
        checkoutCart();

        $response = $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'delivery',
            'address_id' => $this->address->getKey(),
            'courier_service_id' => $this->courierService->getKey(),
            'payment_method_id' => $this->paymentMethod->getKey(),
        ]);

        $order = Order::acrossBranches()->sole();

        $response->assertRedirect(route('shop.account.orders.show', [$order, 'baru' => 1]));

        expect($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->user_id)->toBe($this->customer->getKey())
            ->and(StockReservation::acrossBranches()->active()->count())->toBe(1);
    });

    it('creates a pickup order with no address', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'pickup',
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertRedirect();

        expect(Order::acrossBranches()->sole()->shipping_address_line)->toBeNull();
    });

    it('refuses a pickup that also names an address', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'pickup',
                'address_id' => $this->address->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('address_id');

        expect(Order::acrossBranches()->count())->toBe(0);
    });

    it('requires an address for a delivery', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('address_id');
    });

    it('rejects a fulfillment that is not a real one', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'teleport',
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('fulfillment_type');
    });

    it('explains a refusal on the screen instead of placing the order', function () {
        checkoutCart(stock: 1, quantity: 5);

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $this->address->getKey(),
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('checkout');

        expect(Order::acrossBranches()->count())->toBe(0);
    });

    it('refuses another customer address', function () {
        checkoutCart();
        $stranger = Address::factory()->for(User::factory()->customer())->create();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $stranger->getKey(),
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('checkout');

        expect(Order::acrossBranches()->count())->toBe(0);
    });

    it('turns a guest away', function () {
        $this->post(route('shop.checkout.store'), ['fulfillment_type' => 'pickup'])
            ->assertRedirect(route('login'));
    });

    it('refuses a checkout that names no payment method', function () {
        checkoutCart();

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $this->address->getKey(),
                'courier_service_id' => $this->courierService->getKey(),
            ])
            ->assertSessionHasErrors('payment_method_id');

        expect(Order::acrossBranches()->count())->toBe(0);
    });

    it('empties the cart so the order cannot be placed twice', function () {
        checkoutCart();

        $this->actingAs($this->customer)->post(route('shop.checkout.store'), [
            'fulfillment_type' => 'delivery',
            'address_id' => $this->address->getKey(),
            'courier_service_id' => $this->courierService->getKey(),
            'payment_method_id' => $this->paymentMethod->getKey(),
        ]);

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'delivery',
                'address_id' => $this->address->getKey(),
                'courier_service_id' => $this->courierService->getKey(),
                'payment_method_id' => $this->paymentMethod->getKey(),
            ])
            ->assertSessionHasErrors('checkout');

        expect(Order::acrossBranches()->count())->toBe(1);
    });
});
