<?php

use App\Actions\Order\ReleaseReservation;
use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
 * The order queue in the panel (P1-E7-T8, T9, T10).
 *
 * One set of screens for central and branch staff, narrowed rather than
 * duplicated (ADR-016). Because `SubstituteBindings` runs after
 * `SetBranchContext` (ADR-027), another branch's order id in the URL answers
 * 404 rather than 403 — the point being that a branch never learns the order
 * exists.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->otherWarehouse = Warehouse::factory()->for($this->otherBranch)->default()->create();

    $this->central = User::factory()->adminPusat()->create();
    $this->branchAdmin = User::factory()->adminCabang($this->branch)->create();
});

/**
 * An unpaid order holding stock at a branch's own warehouse.
 */
function panelOrder(Branch $branch, Warehouse $warehouse, int $onHand = 10, int $held = 2): Order
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($warehouse)->forVariant($variant)->quantity($onHand)->create();

    $order = Order::factory()->forBranch($branch)->create();
    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity($held)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $held]]);

    return $order->fresh();
}

describe('the listing', function () {
    it('shows every branch orders to central staff', function () {
        Order::factory()->forBranch($this->branch)->create();
        Order::factory()->forBranch($this->otherBranch)->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/orders/index')
                ->has('orders.data', 2)
                ->where('is_central', true)
                ->has('options.branches', 2));
    });

    it('shows a branch admin only their own branch orders (BR-3)', function () {
        $mine = Order::factory()->forBranch($this->branch)->create();
        Order::factory()->forBranch($this->otherBranch)->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->getKey())
                ->where('is_central', false)
                ->has('options.branches', 0));
    });

    it('narrows by status', function () {
        Order::factory()->forBranch($this->branch)->create();
        Order::factory()->forBranch($this->branch)->completed()->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.index', ['status' => 'completed']))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    });

    it('narrows by branch for central staff', function () {
        Order::factory()->forBranch($this->branch)->create();
        Order::factory()->forBranch($this->otherBranch)->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.index', ['branch' => $this->otherBranch->getKey()]))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.branch.id', $this->otherBranch->getKey()));
    });

    it('finds an order by its number', function () {
        $order = Order::factory()->forBranch($this->branch)->create();
        Order::factory()->forBranch($this->branch)->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.index', ['search' => $order->order_number]))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    });

    it('rejects a sort column that is not whitelisted', function () {
        $this->actingAs($this->central)
            ->get(route('admin.orders.index', ['sort' => 'internal_note']))
            ->assertSessionHasErrors('sort');
    });

    it('counts what is waiting, whatever the filters say', function () {
        Order::factory()->forBranch($this->branch)->count(2)->create();
        Order::factory()->forBranch($this->branch)->paid()->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.index', ['status' => 'completed']))
            ->assertInertia(fn ($page) => $page
                ->where('summary.pending_payment', 2)
                ->where('summary.paid', 1)
                ->where('summary.open', 3)
                ->has('orders.data', 0));
    });

    it('refuses an account without the permission', function () {
        $this->actingAs(User::factory()->customer()->create())
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    });
});

describe('the detail screen', function () {
    it('shows the order, its holds and the moves it can make', function () {
        $order = panelOrder($this->branch, $this->warehouse);

        $this->actingAs($this->central)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/orders/show')
                ->where('order.order_number', $order->order_number)
                ->has('order.items', 1)
                ->has('reservations', 1)
                ->where('reservations.0.status', 'active')
                // pending_payment → paid, cancelled, expired
                ->has('next_statuses', 3));
    });

    it('does not find another branch order, so its existence stays hidden', function () {
        $order = Order::factory()->forBranch($this->otherBranch)->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertNotFound();
    });

    it('offers the branch move to central staff only', function () {
        $order = panelOrder($this->branch, $this->warehouse);

        $this->actingAs($this->central)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.assign_branch', true)
                ->has('branches', 1));

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.can.assign_branch', false)
                ->has('branches', 0));
    });

    it('offers no move at all for a finished order', function () {
        $order = Order::factory()->forBranch($this->branch)->cancelled()->create();

        $this->actingAs($this->central)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->has('next_statuses', 0)
                ->where('order.can.update_status', false)
                ->where('order.can.cancel', false));
    });
});

describe('moving the status', function () {
    it('marks an order paid and takes the goods off the shelf', function () {
        $order = panelOrder($this->branch, $this->warehouse, 10, 2);

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.status.update', $order), [
                'status' => 'paid',
                'note' => 'Transfer diterima',
            ])
            ->assertRedirect();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(8)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and($order->statusHistories()->sole()->note)->toBe('Transfer diterima');
    });

    it('refuses an illegal move and leaves the order where it was', function () {
        $order = panelOrder($this->branch, $this->warehouse);

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.status.update', $order), ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('rejects a status that is not a real one', function () {
        $order = Order::factory()->forBranch($this->branch)->create();

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.status.update', $order), ['status' => 'teleported'])
            ->assertSessionHasErrors('status');
    });

    it('refuses a cashier, who may read orders but not move them', function () {
        $order = Order::factory()->forBranch($this->branch)->create();
        $kasir = User::factory()->kasir($this->branch)->create();

        $this->actingAs($kasir)
            ->put(route('admin.orders.status.update', $order), ['status' => 'paid'])
            ->assertForbidden();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('does not find another branch order to move', function () {
        $order = Order::factory()->forBranch($this->otherBranch)->create();

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.status.update', $order), ['status' => 'paid'])
            ->assertNotFound();
    });

    it('refuses to mark an order paid once its stock has been given back (HIGH-03)', function () {
        $order = panelOrder($this->branch, $this->warehouse, 10, 2);
        app(ReleaseReservation::class)->handleForOrder($order);

        // Committing nothing and reporting success would sell the goods with no
        // units leaving `stocks` — said in words here rather than as a 500.
        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.status.update', $order), ['status' => 'paid'])
            ->assertSessionHasErrors('status');

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Unpaid)
            ->and(Stock::acrossBranches()->sole()->quantity)->toBe(10)
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0);
    });
});

describe('cancelling', function () {
    it('cancels and releases the hold', function () {
        $order = panelOrder($this->branch, $this->warehouse, 10, 2);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.cancel', $order), ['reason' => 'Pembeli tidak membalas'])
            ->assertRedirect();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancel_reason)->toBe('Pembeli tidak membalas')
            ->and(Stock::acrossBranches()->sole()->reserved_quantity)->toBe(0)
            ->and(StockReservation::acrossBranches()->sole()->status)->toBe(ReservationStatus::Released);
    });

    it('cancels an order the branch had already started, unlike a customer', function () {
        $order = panelOrder($this->branch, $this->warehouse);
        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.cancel', $order->fresh()))
            ->assertRedirect();

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('refuses to cancel an order that is already final', function () {
        $order = Order::factory()->forBranch($this->branch)->cancelled()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.cancel', $order))
            ->assertForbidden();
    });

    it('refuses an account without the cancel permission', function () {
        $order = Order::factory()->forBranch($this->branch)->create();
        $gudang = User::factory()->staffGudang($this->branch)->create();

        $this->actingAs($gudang)
            ->post(route('admin.orders.cancel', $order))
            ->assertForbidden();
    });
});

describe('moving the processing branch', function () {
    it('lets central staff move an order whose destination has the stock', function () {
        $order = panelOrder($this->branch, $this->warehouse, 10, 2);
        Stock::factory()->inWarehouse($this->otherWarehouse)
            ->forVariant($order->items()->sole()->variant)
            ->quantity(10)
            ->create();

        $this->actingAs($this->central)
            ->put(route('admin.orders.branch.update', $order), [
                'branch_id' => $this->otherBranch->getKey(),
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        expect(Order::acrossBranches()->sole()->branch_id)->toBe($this->otherBranch->getKey())
            ->and(StockReservation::acrossBranches()->active()->sole()->branch_id)
            ->toBe($this->otherBranch->getKey());
    });

    it('refuses when the destination is short, and changes nothing', function () {
        $order = panelOrder($this->branch, $this->warehouse, 10, 5);
        Stock::factory()->inWarehouse($this->otherWarehouse)
            ->forVariant($order->items()->sole()->variant)
            ->quantity(1)
            ->create();

        $this->actingAs($this->central)
            ->put(route('admin.orders.branch.update', $order), [
                'branch_id' => $this->otherBranch->getKey(),
            ])
            ->assertSessionHasErrors('branch_id');

        expect(Order::acrossBranches()->sole()->branch_id)->toBe($this->branch->getKey())
            ->and(StockReservation::acrossBranches()->active()->sole()->branch_id)
            ->toBe($this->branch->getKey());
    });

    it('refuses a move to the branch the order is already at', function () {
        $order = panelOrder($this->branch, $this->warehouse);

        $this->actingAs($this->central)
            ->put(route('admin.orders.branch.update', $order), [
                'branch_id' => $this->branch->getKey(),
            ])
            ->assertSessionHasErrors('branch_id');
    });

    it('refuses a branch admin even when granted the permission', function () {
        $order = panelOrder($this->branch, $this->warehouse);
        $this->branchAdmin->givePermissionTo('orders.assign_branch');

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.orders.branch.update', $order), [
                'branch_id' => $this->otherBranch->getKey(),
            ])
            ->assertForbidden();

        expect(Order::acrossBranches()->sole()->branch_id)->toBe($this->branch->getKey());
    });

    it('rejects a destination that does not exist', function () {
        $order = panelOrder($this->branch, $this->warehouse);

        $this->actingAs($this->central)
            ->put(route('admin.orders.branch.update', $order), ['branch_id' => 99999])
            ->assertSessionHasErrors('branch_id');
    });
});
