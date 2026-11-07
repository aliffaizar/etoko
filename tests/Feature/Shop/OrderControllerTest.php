<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;

/*
 * A customer's own orders (P1-E7-T6, T9).
 *
 * Scoped by `user_id` rather than by policy: a shopper holds no `orders.*`
 * permission, and another customer's order must be *not found* rather than
 * refused, so nothing leaks about it existing.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
});

/**
 * An unpaid order of the test customer, holding stock at the test branch.
 */
function customerOrderHoldingStock(int $onHand = 10, int $held = 2): Order
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)
        ->quantity($onHand)->create();

    $order = Order::factory()
        ->forBranch(test()->branch)
        ->forCustomer(test()->customer)
        ->create();

    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    return $order->fresh();
}

describe('the history', function () {
    it('lists the customer own orders, newest first', function () {
        $older = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->create(['created_at' => now()->subDay()]);
        $newer = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/orders')
                ->has('orders.data', 2)
                ->where('orders.data.0.id', $newer->getKey())
                ->where('orders.data.1.id', $older->getKey()));
    });

    it('never lists another customer orders', function () {
        Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();
        Order::factory()->forBranch($this->branch)
            ->forCustomer(User::factory()->customer()->create())->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.index'))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    });

    it('narrows by status', function () {
        Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();
        Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->completed()->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.index', ['status' => 'completed']))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'completed'));
    });

    it('ignores a status that is not a real one rather than erroring', function () {
        Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.index', ['status' => 'bukan-status']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('filters.status', ''));
    });

    it('turns a guest away', function () {
        $this->get(route('shop.account.orders.index'))->assertRedirect(route('login'));
    });
});

describe('one order', function () {
    it('shows the order with its lines and timeline', function () {
        $order = customerOrderHoldingStock();
        app(TransitionOrderStatus::class)->recordInitial($order);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/order')
                ->where('order.order_number', $order->order_number)
                ->has('order.items', 1)
                ->has('order.timeline', 1));
    });

    it('shows the confirmation panel only when checkout just sent them here', function () {
        $order = customerOrderHoldingStock();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', [$order, 'baru' => 1]))
            ->assertInertia(fn ($page) => $page->where('just_placed', true));

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('just_placed', false));
    });

    it('does not find another customer order', function () {
        $order = Order::factory()->forBranch($this->branch)
            ->forCustomer(User::factory()->customer()->create())->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertNotFound();
    });

    it('offers cancelling only while the order awaits payment', function () {
        $order = customerOrderHoldingStock();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('order.can.cancel', true));

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order->fresh()))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.cancel', false)
                ->where('cancel_blocked_reason', 'Pesanan sudah mulai diproses. Hubungi cabang untuk membatalkannya.'));
    });
});

describe('cancelling', function () {
    it('cancels an unpaid order and gives the held stock back', function () {
        $order = customerOrderHoldingStock(10, 2);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.cancel', $order), ['reason' => 'Salah varian'])
            ->assertRedirect();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancel_reason)->toBe('Salah varian')
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
    });

    it('records a default reason when none is given', function () {
        $order = customerOrderHoldingStock();

        $this->actingAs($this->customer)->post(route('shop.account.orders.cancel', $order));

        expect($order->fresh()->cancel_reason)->toBe('Dibatalkan oleh pelanggan.');
    });

    it('refuses to cancel an order the branch has begun processing', function () {
        $order = customerOrderHoldingStock();
        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.cancel', $order->fresh()))
            ->assertSessionHasErrors('reason');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('does not find another customer order to cancel', function () {
        $order = Order::factory()->forBranch($this->branch)
            ->forCustomer(User::factory()->customer()->create())->create();

        $this->actingAs($this->customer)
            ->post(route('shop.account.orders.cancel', $order))
            ->assertNotFound();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('turns a guest away', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();

        $this->post(route('shop.account.orders.cancel', $order))->assertRedirect(route('login'));
    });
});
