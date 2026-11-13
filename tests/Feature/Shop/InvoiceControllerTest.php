<?php

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

/*
 * The customer downloading their own invoice (P2-E6-T2).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->customer = User::factory()->customer()->create();
});

it('downloads the invoice of a paid order as a PDF', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()->create();

    $response = $this->actingAs($this->customer)
        ->get(route('shop.account.orders.invoice', $order))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $invoice = Invoice::where('order_id', $order->id)->sole();

    expect($response->headers->get('content-disposition'))->toContain("{$invoice->invoice_number}.pdf")
        ->and($response->getContent())->toStartWith('%PDF');
});

it('issues the invoice on the first download when none was issued yet', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()->create();

    expect(Invoice::count())->toBe(0);

    $this->actingAs($this->customer)->get(route('shop.account.orders.invoice', $order))->assertOk();
    $this->actingAs($this->customer)->get(route('shop.account.orders.invoice', $order))->assertOk();

    expect(Invoice::where('order_id', $order->id)->count())->toBe(1);
});

it('has no invoice for an order that has not been paid for', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();

    $this->actingAs($this->customer)
        ->get(route('shop.account.orders.invoice', $order))
        ->assertNotFound();

    expect(Invoice::count())->toBe(0);
});

it('does not reveal another customer invoice', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create();

    $this->actingAs($this->customer)
        ->get(route('shop.account.orders.invoice', $order))
        ->assertNotFound();
});

it('sends a guest to log in', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()->create();

    $this->get(route('shop.account.orders.invoice', $order))->assertRedirect(route('login'));
});

it('offers the download on the order page only once it has been paid for', function () {
    $paid = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()->create();
    $unpaid = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->create();

    $this->actingAs($this->customer)
        ->get(route('shop.account.orders.show', $paid))
        ->assertInertia(fn ($page) => $page->where('invoice.number', null));

    $this->actingAs($this->customer)
        ->get(route('shop.account.orders.show', $unpaid))
        ->assertInertia(fn ($page) => $page->where('invoice', null));
});
