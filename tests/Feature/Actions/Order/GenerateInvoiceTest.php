<?php

use App\Actions\Order\GenerateInvoice;
use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvoiceUnavailableException;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;

/*
 * Issuing the invoice (P2-E6-T1, ADR-059).
 *
 * One per order, issued when the order is paid for and never rewritten: the
 * number, the date and the total are what the customer was given.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->action = app(GenerateInvoice::class);
});

it('issues a numbered invoice carrying the order total', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create(['grand_total' => 275_000]);

    $invoice = $this->action->handle($order);

    expect($invoice->order_id)->toBe($order->id)
        ->and($invoice->invoice_number)->toStartWith('INV-'.now()->format('Ymd').'-')
        ->and($invoice->total)->toBe(275_000)
        ->and($invoice->issued_at)->not->toBeNull();
});

it('returns the invoice the order already has instead of issuing a second', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create();

    $first = $this->action->handle($order);
    $second = $this->action->handle($order->fresh());

    expect($second->is($first))->toBeTrue()
        ->and(Invoice::where('order_id', $order->id)->count())->toBe(1);
});

it('keeps the total it was issued with when the order changes afterwards', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create(['grand_total' => 100_000]);
    $this->action->handle($order);

    $order->update(['grand_total' => 90_000]);

    expect($this->action->handle($order->fresh())->total)->toBe(100_000);
});

it('refuses an order that has not been paid for', function () {
    $order = Order::factory()->forBranch($this->branch)->create();

    $this->action->handle($order);
})->throws(InvoiceUnavailableException::class, 'Invoice terbit setelah pesanan dibayar.');

it('still issues one for an order cancelled after it was paid', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create();
    $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    expect($this->action->handle($order->fresh()))->toBeInstanceOf(Invoice::class);
});

it('is issued by the transition to paid itself', function () {
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($this->warehouse)->forVariant($variant)->quantity(5)->create();
    $order = Order::factory()->forBranch($this->branch)->create();
    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity(2)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => 2]]);

    expect(Invoice::count())->toBe(0);

    app(TransitionOrderStatus::class)->handle($order->fresh(), OrderStatus::Paid);

    expect(Invoice::where('order_id', $order->id)->sole()->total)->toBe($order->grand_total);
});

it('is not issued by a transition that is not to paid', function () {
    $order = Order::factory()->forBranch($this->branch)->paid()->create();

    app(TransitionOrderStatus::class)->handle($order, OrderStatus::Processing);

    expect(Invoice::count())->toBe(0);
});
