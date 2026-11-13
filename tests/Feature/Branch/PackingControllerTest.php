<?php

use App\Enums\OrderStatus;
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
 * The packing list (P3-E6-T4): paid orders split per warehouse and shelf,
 * and "Mulai kemas" — paid → processing — for warehouse staff.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true]);
    $this->main = Warehouse::factory()->default()->for($this->branch)->create();
    $this->overflow = Warehouse::factory()->for($this->branch)->create(['code' => 'WH-02', 'name' => 'Gudang Cadangan']);
});

function packingVariant(): ProductVariant
{
    return Product::factory()->withDefaultVariant()->create()->variants()->sole();
}

/**
 * A paid order of one variant whose units were committed from the given
 * warehouses, as `StockAllocator` + `CommitReservation` leave it.
 *
 * @param  array<int, array{0: Warehouse, 1: int}>  $holds
 */
function paidOrderFrom(Branch $branch, ProductVariant $variant, array $holds, OrderStatus $status = OrderStatus::Paid): Order
{
    $order = Order::factory()->forBranch($branch)->status($status)->create();
    OrderItem::factory()->forOrder($order)->forVariant($variant)
        ->quantity(array_sum(array_map(fn (array $hold): int => $hold[1], $holds)))
        ->create();

    foreach ($holds as [$warehouse, $quantity]) {
        StockReservation::factory()->forOrder($order)->inWarehouse($warehouse)->forVariant($variant)
            ->quantity($quantity)->withoutExpiry()->committed()->create();
    }

    return $order;
}

it('lists paid orders with what to take from which warehouse and shelf', function () {
    $variant = packingVariant();
    Stock::factory()->inWarehouse($this->main)->forVariant($variant)->quantity(10)->create(['shelf_location' => 'A-3']);
    $order = paidOrderFrom($this->branch, $variant, [[$this->main, 2], [$this->overflow, 1]]);

    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->get(route('branch.packing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/packing/index')
            ->where('counts', ['to_pack' => 1, 'packing' => 0])
            ->has('orders.data', 1)
            ->where('orders.data.0.order_number', $order->order_number)
            ->where('orders.data.0.unit_count', 3)
            ->where('orders.data.0.picks.0.warehouse', 'Gudang Utama')
            ->where('orders.data.0.picks.0.lines.0.quantity', 2)
            ->where('orders.data.0.picks.0.lines.0.shelf_location', 'A-3')
            ->where('orders.data.0.picks.1.warehouse', 'Gudang Cadangan')
            ->where('orders.data.0.picks.1.lines.0.quantity', 1)
            ->where('orders.data.0.picks.1.lines.0.shelf_location', null));
});

it('keeps unpaid, in-progress and other branches orders off the to-pack tab', function () {
    $variant = packingVariant();
    $waiting = paidOrderFrom($this->branch, $variant, [[$this->main, 1]]);
    paidOrderFrom($this->branch, $variant, [[$this->main, 1]], OrderStatus::Processing);
    Order::factory()->forBranch($this->branch)->create();
    $elsewhere = Branch::factory()->create();
    paidOrderFrom($elsewhere, $variant, [[Warehouse::factory()->default()->for($elsewhere)->create(), 1]]);

    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->get(route('branch.packing.index'))
        ->assertInertia(fn ($page) => $page
            ->where('counts', ['to_pack' => 1, 'packing' => 1])
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $waiting->id));
});

it('shows the orders being packed on their own tab', function () {
    $variant = packingVariant();
    $inHand = paidOrderFrom($this->branch, $variant, [[$this->main, 1]], OrderStatus::Processing);
    paidOrderFrom($this->branch, $variant, [[$this->main, 1]]);

    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->get(route('branch.packing.index', ['stage' => 'packing']))
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $inHand->id));
});

it('lets warehouse staff start packing, which moves the order to processing', function () {
    $order = paidOrderFrom($this->branch, packingVariant(), [[$this->main, 1]]);
    $actor = User::factory()->staffGudang($this->branch)->create();

    $this->actingAs($actor)
        ->post(route('branch.packing.start', $order))
        ->assertRedirect();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Processing)
        ->and($order->processed_at)->not->toBeNull()
        ->and($order->statusHistories()->latest('id')->first()->changed_by)->toBe($actor->getKey());
});

it('refuses to start packing an order that is not waiting to be packed', function () {
    $order = paidOrderFrom($this->branch, packingVariant(), [[$this->main, 1]], OrderStatus::Processing);

    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->post(route('branch.packing.start', $order))
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
});

it('404s on another branch order', function () {
    $elsewhere = Branch::factory()->create();
    $order = paidOrderFrom($elsewhere, packingVariant(), [[Warehouse::factory()->default()->for($elsewhere)->create(), 1]]);

    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->post(route('branch.packing.start', $order))
        ->assertNotFound();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('does not give warehouse staff the rest of the order screens', function () {
    $order = paidOrderFrom($this->branch, packingVariant(), [[$this->main, 1]]);
    $actor = User::factory()->staffGudang($this->branch)->create();

    $this->actingAs($actor)->get(route('admin.orders.show', $order))->assertForbidden();
    $this->actingAs($actor)
        ->post(route('admin.orders.cancel', $order), ['reason' => 'Coba-coba'])
        ->assertForbidden();
});

it('refuses a cashier, who sells rather than packs', function () {
    $order = paidOrderFrom($this->branch, packingVariant(), [[$this->main, 1]]);
    $cashier = User::factory()->kasir($this->branch)->create();

    $this->actingAs($cashier)->get(route('branch.packing.index'))->assertForbidden();
    $this->actingAs($cashier)->post(route('branch.packing.start', $order))->assertForbidden();
});

it('lets central staff choose which branch to look at', function () {
    $other = Branch::factory()->create();
    $otherWarehouse = Warehouse::factory()->default()->for($other)->create();
    $order = paidOrderFrom($other, packingVariant(), [[$otherWarehouse, 1]]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('branch.packing.index', ['branch' => $other->id]))
        ->assertInertia(fn ($page) => $page
            ->where('may_choose_branch', true)
            ->where('branch.id', $other->id)
            ->where('orders.data.0.id', $order->id));
});
