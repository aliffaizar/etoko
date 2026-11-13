<?php

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

/*
 * Staff downloading an order's invoice (P2-E6-T2).
 *
 * Whoever may read the order may read its invoice; another branch's order
 * answers 404 before the policy is asked (ADR-027).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->order = Order::factory()->forBranch($this->branch)->paid()->create();
});

it('lets central and the order branch download it', function (string $who) {
    $actor = $who === 'central'
        ? User::factory()->adminPusat()->create()
        : User::factory()->kasir($this->branch)->create();

    $this->actingAs($actor)
        ->get(route('admin.orders.invoice', $this->order))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(Invoice::where('order_id', $this->order->id)->exists())->toBeTrue();
})->with(['central', 'branch cashier']);

it('hides another branch order', function () {
    $this->actingAs(User::factory()->adminCabang($this->otherBranch)->create())
        ->get(route('admin.orders.invoice', $this->order))
        ->assertNotFound();
});

it('refuses an account that may not read orders', function () {
    $this->actingAs(User::factory()->staffGudang($this->branch)->create())
        ->get(route('admin.orders.invoice', $this->order))
        ->assertForbidden();
});

it('has no invoice for an unpaid order', function () {
    $unpaid = Order::factory()->forBranch($this->branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.orders.invoice', $unpaid))
        ->assertNotFound();
});

it('names the issued invoice on the order screen', function () {
    $invoice = Invoice::factory()->create(['order_id' => $this->order->id]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.orders.show', $this->order))
        ->assertInertia(fn ($page) => $page->where('invoice.number', $invoice->invoice_number));
});
