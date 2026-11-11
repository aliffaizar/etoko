<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\ShipmentStatus;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
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

describe('following the parcel', function () {
    it('shows where the parcel is, with the timeline the courier wrote', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->status(OrderStatus::Shipped)->create();

        $shipment = Shipment::factory()->forOrder($order)->inTransit('JNE00123456')->create();
        ShipmentTrackingEvent::factory()->forShipment($shipment)
            ->withStatus(ShipmentStatus::PickedUp)->at(now()->subDay())->create();
        ShipmentTrackingEvent::factory()->forShipment($shipment)
            ->withStatus(ShipmentStatus::InTransit)->at(now()->subHours(2))
            ->create(['description' => 'Berangkat dari Bandung']);

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shipments', 1)
                ->where('shipments.0.tracking_number', 'JNE00123456')
                ->where('shipments.0.status', 'in_transit')
                ->where('shipments.0.status_label', 'Dalam Perjalanan')
                // Oldest first: the sequence it happened in.
                ->has('shipments.0.timeline', 2)
                ->where('shipments.0.timeline.0.status', 'picked_up')
                ->where('shipments.0.timeline.1.description', 'Berangkat dari Bandung')
                ->etc());
    });

    it('offers the courier own tracking page when it publishes one', function () {
        $courier = Courier::factory()->withTrackingUrl()->create();
        $service = CourierService::factory()->forCourier($courier)->create();
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->status(OrderStatus::Shipped)->create();

        Shipment::factory()->forOrder($order)->forService($service)->inTransit('JNE123')->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('shipments.0.tracking_url', 'https://example.test/cek/JNE123')
                ->etc());
    });

    it('keeps the shop own workings out of the customer view', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->status(OrderStatus::Shipped)->create();

        $packer = User::factory()->adminCabang($this->branch)->create(['name' => 'Rina Packer']);
        Shipment::factory()->forOrder($order)->inTransit('JNE123')->create([
            'external_id' => 'bite-order-1',
            'dispatched_by' => $packer->getKey(),
            'tracking_synced_at' => now(),
            'raw_payload' => ['secret' => 'provider payload'],
        ]);

        $response = $this->actingAs($this->customer)->get(route('shop.account.orders.show', $order));

        expect($response->content())->not->toContain('bite-order-1')
            ->not->toContain('Rina Packer')
            ->not->toContain('provider payload');
    });

    it('shows both journeys of a parcel that came back and was re-sent', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->status(OrderStatus::Shipped)->create();

        Shipment::factory()->forOrder($order)->returned()->create();
        Shipment::factory()->forOrder($order)->inTransit('JNE999')->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page->has('shipments', 2)->etc());
    });

    it('shows the collection code only while the goods are waiting', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->readyForPickup('K7M2PQ')->create();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('pickup_code', 'K7M2PQ')->etc());

        $order->forceFill(['status' => OrderStatus::Completed, 'completed_at' => now()])->saveQuietly();

        $this->actingAs($this->customer)
            ->get(route('shop.account.orders.show', $order->fresh()))
            ->assertInertia(fn ($page) => $page->where('pickup_code', null)->etc());
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
