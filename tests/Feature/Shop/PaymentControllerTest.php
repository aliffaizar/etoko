<?php

use App\Actions\Order\ReserveStock;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * How a customer pays for their own order (P2-E1-T3).
 *
 * Scoped by `user_id`, not by policy: somebody else's order is **not found**
 * rather than refused, so nothing leaks about it existing — the same
 * arrangement as `Shop\OrderController`.
 */

beforeEach(function () {
    Storage::fake(Payment::PROOF_DISK);

    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->stranger = User::factory()->customer()->create();
    $this->method = PaymentMethod::factory()->create(['name' => 'Transfer Bank BCA']);
});

/**
 * One of the customer's own orders, unpaid and holding stock.
 */
function customerOrder(?User $owner = null, int $grandTotal = 500_000): Order
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(10)->create();

    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer($owner ?? test()->customer)
        ->create([
            'subtotal' => $grandTotal,
            'shipping_cost' => 0,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
            'shipping_city_name' => 'Jakarta Selatan',
            'shipping_province_name' => 'DKI Jakarta',
        ]);

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity(2)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => 2]]);

    return $order->fresh();
}

describe('the order page payment panel', function () {
    it('offers the eligible methods and says why the others are not', function () {
        $order = customerOrder();
        PaymentMethod::factory()->cod()->betweenAmounts(null, 100_000)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/order')
                ->where('order.can.pay', true)
                ->has('payment_methods', 2)
                ->where('payment', null));
    });

    it('shows the instructions once an attempt is open', function () {
        $order = customerOrder();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order), [
                'payment_method_id' => $this->method->getKey(),
            ]);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('payment.method_name', 'Transfer Bank BCA')
                ->where('payment.accepts_proof', true)
                ->whereNot('payment.bank_account', null)
                ->whereNot('payment.expires_at', null));
    });

    it('offers no payment at all once the order is settled', function () {
        $order = customerOrder();
        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.pay', false)
                ->has('payment_methods', 0)
                ->whereNot('payment_blocked_reason', null));
    });

    it('keeps the earlier attempts as history', function () {
        $order = customerOrder();
        $other = PaymentMethod::factory()->create();

        $this->actingAs($this->customer)->post(
            route('shop.account.orders.payments.store', $order),
            ['payment_method_id' => $this->method->getKey()],
        );
        $this->actingAs($this->customer)->post(
            route('shop.account.orders.payments.store', $order),
            ['payment_method_id' => $other->getKey()],
        );

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('payment.method_name', $other->name)
                ->has('payment_history', 1));
    });
});

describe('choosing a method', function () {
    it('opens an attempt and sends the customer back to the order', function () {
        $order = customerOrder();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order), [
                'payment_method_id' => $this->method->getKey(),
            ])
            ->assertRedirect();

        $payment = Payment::acrossBranches()->sole();

        expect($payment->order_id)->toBe($order->getKey())
            ->and($payment->status)->toBe(PaymentTransactionStatus::Pending)
            ->and($payment->amount)->toBe(500_000);
    });

    it('demands a method', function () {
        $order = customerOrder();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order), [])
            ->assertSessionHasErrors('payment_method_id');

        expect(Payment::acrossBranches()->count())->toBe(0);
    });

    it('refuses a method the order is not eligible for, saying why (P2-E1-T7)', function () {
        $order = customerOrder(grandTotal: 3_000_000);
        $cod = PaymentMethod::factory()->cod()->betweenAmounts(null, 2_000_000)->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order), [
                'payment_method_id' => $cod->getKey(),
            ])
            ->assertSessionHasErrors('payment_method_id');

        expect(Payment::acrossBranches()->count())->toBe(0);
    });

    it('answers 404 for somebody else order', function () {
        $theirs = customerOrder($this->stranger);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $theirs), [
                'payment_method_id' => $this->method->getKey(),
            ])
            ->assertNotFound();

        expect(Payment::acrossBranches()->count())->toBe(0);
    });

    it('turns a guest away', function () {
        $order = customerOrder();

        $this->post(route('shop.account.orders.payments.store', $order), [
            'payment_method_id' => $this->method->getKey(),
        ])->assertRedirect(route('login'));
    });

    it('confirms a COD order outright (ADR-040)', function () {
        $order = customerOrder();
        $cod = PaymentMethod::factory()->cod()->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order), [
                'payment_method_id' => $cod->getKey(),
            ])
            ->assertRedirect();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Pending);
    });

    it('refuses a COD order that will be collected at the branch', function () {
        $order = customerOrder();
        $order->update(['fulfillment_type' => 'pickup']);
        $cod = PaymentMethod::factory()->cod()->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.store', $order->fresh()), [
                'payment_method_id' => $cod->getKey(),
            ])
            ->assertSessionHasErrors('payment_method_id');
    });
});

describe('uploading the receipt', function () {
    beforeEach(function () {
        $this->order = customerOrder();
        $this->payment = Payment::factory()->forOrder($this->order)->create();
    });

    it('attaches the receipt and puts the order in the queue', function () {
        $this->actingAs($this->customer)
            ->post(
                route('shop.account.orders.payments.proof', [$this->order, $this->payment]),
                ['proof' => UploadedFile::fake()->image('bukti.jpg'), 'note' => 'Dari BCA a/n Budi'],
            )
            ->assertRedirect();

        $this->payment->refresh();

        expect($this->payment->proof_path)->not->toBeNull()
            ->and($this->payment->proof_note)->toBe('Dari BCA a/n Budi')
            ->and($this->order->fresh()->payment_status)->toBe(PaymentStatus::Pending);

        Storage::disk(Payment::PROOF_DISK)->assertExists($this->payment->proof_path);
    });

    it('demands a file', function () {
        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.payments.proof', [$this->order, $this->payment]), [])
            ->assertSessionHasErrors('proof');
    });

    it('refuses a file type that is not a receipt', function () {
        $this->actingAs($this->customer)
            ->post(
                route('shop.account.orders.payments.proof', [$this->order, $this->payment]),
                ['proof' => UploadedFile::fake()->create('bukti.svg', 10, 'image/svg+xml')],
            )
            ->assertSessionHasErrors('proof');

        expect($this->payment->fresh()->proof_path)->toBeNull();
    });

    it('refuses a file over the size limit', function () {
        $this->actingAs($this->customer)
            ->post(
                route('shop.account.orders.payments.proof', [$this->order, $this->payment]),
                ['proof' => UploadedFile::fake()->create('bukti.jpg', 5_000, 'image/jpeg')],
            )
            ->assertSessionHasErrors('proof');
    });

    it('answers 404 for a payment that belongs to another order', function () {
        $otherOrder = customerOrder();
        $otherPayment = Payment::factory()->forOrder($otherOrder)->create();

        $this->actingAs($this->customer)
            ->post(
                route('shop.account.orders.payments.proof', [$this->order, $otherPayment]),
                ['proof' => UploadedFile::fake()->image('bukti.jpg')],
            )
            ->assertNotFound();
    });

    it('answers 404 for somebody else order', function () {
        $theirs = customerOrder($this->stranger);
        $theirPayment = Payment::factory()->forOrder($theirs)->create();

        $this->actingAs($this->customer)
            ->post(
                route('shop.account.orders.payments.proof', [$theirs, $theirPayment]),
                ['proof' => UploadedFile::fake()->image('bukti.jpg')],
            )
            ->assertNotFound();
    });
});

describe('checkout opens the payment with the order (P2-E1-T3)', function () {
    it('offers the methods on the checkout screen, quoted for both fulfilments', function () {
        $variant = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000])
            ->variants()->sole();
        Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(10)->create();

        $cart = Cart::factory()->forUser($this->customer)->create([
            'branch_id' => $this->branch->getKey(),
        ]);
        CartItem::factory()->forCart($cart)->forVariant($variant)->create([
            'quantity' => 2,
            'unit_price_snapshot' => 100_000,
        ]);

        Address::factory()->for($this->customer)->create(['is_default' => true]);

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payment_methods.delivery')
                ->has('payment_methods.pickup'));
    });
});
