<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReservationStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\OrderDemoSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
 * The order demo data (P1-E8-T1).
 *
 * The point of these tests is the invariant, not the fixture. The seeder goes
 * through `PlaceOrder` and `TransitionOrderStatus`, so what it leaves behind
 * has to satisfy everything a real order satisfies: a timeline, holds that
 * match its status, and stock figures the ledger still explains. A seeder that
 * wrote `orders` directly would pass none of this — which is the drift these
 * tests exist to catch.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoDataSeeder::class);
    $this->seed(CatalogDemoSeeder::class);
    $this->seed(InventoryDemoSeeder::class);
});

it('gives every branch some orders to show', function () {
    $this->seed(OrderDemoSeeder::class);

    $byBranch = Order::acrossBranches()->get()->groupBy('branch_id');

    expect($byBranch)->not->toBeEmpty()
        ->and($byBranch->every(fn ($orders): bool => $orders->count() > 1))->toBeTrue();
});

it('leaves one of each state the screens need to show', function () {
    $this->seed(OrderDemoSeeder::class);

    $statuses = Order::acrossBranches()->pluck('status')->map->value->unique();

    expect($statuses)->toContain(OrderStatus::PendingPayment->value)
        ->toContain(OrderStatus::Processing->value)
        ->toContain(OrderStatus::Completed->value)
        ->toContain(OrderStatus::Cancelled->value);
});

it('gives every order lines, and every line a snapshot', function () {
    $this->seed(OrderDemoSeeder::class);

    $items = OrderItem::query()->get();

    expect($items)->not->toBeEmpty()
        ->and(Order::acrossBranches()->doesntHave('items')->count())->toBe(0)
        ->and($items->every(fn (OrderItem $item): bool => $item->sku !== ''
            && $item->product_name !== ''
            && $item->unit_price > 0
            && $item->subtotal === $item->unit_price * $item->quantity - $item->discount_amount))
        ->toBeTrue();
});

it('gives every order a timeline, because every one went through the real action', function () {
    $this->seed(OrderDemoSeeder::class);

    expect(Order::acrossBranches()->doesntHave('statusHistories')->count())->toBe(0);
});

it('keeps each order total equal to the lines it is built from', function () {
    $this->seed(OrderDemoSeeder::class);

    foreach (Order::acrossBranches()->with('items')->get() as $order) {
        expect($order->subtotal)->toBe((int) $order->items->sum('subtotal'))
            ->and($order->grand_total)->toBe(
                $order->subtotal - $order->discount_total + $order->shipping_cost + $order->tax_total,
            );
    }
});

it('holds stock only for the orders that should be holding it', function () {
    $this->seed(OrderDemoSeeder::class);

    foreach (Order::acrossBranches()->with('stockReservations')->get() as $order) {
        $active = $order->stockReservations->where('status', ReservationStatus::Active);
        $committed = $order->stockReservations->where('status', ReservationStatus::Committed);

        match (true) {
            $order->status === OrderStatus::PendingPayment => expect($active)->not->toBeEmpty(),
            $order->status === OrderStatus::Cancelled => expect($active)->toBeEmpty(),
            // Everything past payment has taken its goods off the shelf.
            default => expect($committed)->not->toBeEmpty()->and($active)->toBeEmpty(),
        };
    }
});

it('leaves every stock figure reconcilable against the ledger', function () {
    $this->seed(OrderDemoSeeder::class);

    $ledgerTotals = StockMovement::acrossBranches()
        ->selectRaw('warehouse_id, product_variant_id, sum(quantity_change) as total')
        ->groupBy('warehouse_id', 'product_variant_id')
        ->get()
        ->keyBy(fn ($row): string => "{$row->warehouse_id}:{$row->product_variant_id}");

    foreach (Stock::acrossBranches()->get() as $stock) {
        $key = "{$stock->warehouse_id}:{$stock->product_variant_id}";

        expect((int) $ledgerTotals[$key]->total)->toBe($stock->quantity);
    }
});

it('keeps reserved_quantity equal to the holds that explain it', function () {
    $this->seed(OrderDemoSeeder::class);

    $held = StockReservation::acrossBranches()
        ->active()
        ->selectRaw('warehouse_id, product_variant_id, sum(quantity) as total')
        ->groupBy('warehouse_id', 'product_variant_id')
        ->get()
        ->keyBy(fn ($row): string => "{$row->warehouse_id}:{$row->product_variant_id}");

    foreach (Stock::acrossBranches()->get() as $stock) {
        $key = "{$stock->warehouse_id}:{$stock->product_variant_id}";

        expect($stock->reserved_quantity)->toBe((int) ($held[$key]->total ?? 0));
    }
});

it('never leaves a branch holding more than it has on the shelf (BR-6)', function () {
    $this->seed(OrderDemoSeeder::class);

    foreach (Stock::acrossBranches()->get() as $stock) {
        expect($stock->quantity)->toBeGreaterThanOrEqual($stock->reserved_quantity)
            ->and($stock->quantity)->toBeGreaterThanOrEqual(0);
    }
});

it('pushes the live hold past the expiry sweep, so the demo survives the first minute', function () {
    $this->seed(OrderDemoSeeder::class);

    $holds = StockReservation::acrossBranches()->active()->get();

    expect($holds)->not->toBeEmpty()
        ->and($holds->every(fn (StockReservation $hold): bool => $hold->expires_at->isAfter(now()->addDay())))
        ->toBeTrue();
});

it('gives the payment screen one of each state to show (P2-E1-T1)', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(OrderDemoSeeder::class);

    $states = Payment::acrossBranches()->pluck('status')->map->value->unique();

    expect(Payment::acrossBranches()->count())->toBeGreaterThan(0)
        ->and($states)->toContain(PaymentTransactionStatus::Pending->value)
        ->toContain(PaymentTransactionStatus::Success->value)
        ->toContain(PaymentTransactionStatus::Expired->value);
});

it('keeps every payment branch equal to its order branch (ADR-038)', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(OrderDemoSeeder::class);

    $drifted = Payment::acrossBranches()
        ->with('order')
        ->get()
        ->filter(fn (Payment $payment): bool => $payment->branch_id !== $payment->order->branch_id);

    expect($drifted)->toBeEmpty();
});

it('keeps every payment amount equal to its order total plus the method fee (ADR-039)', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(OrderDemoSeeder::class);

    $drifted = Payment::acrossBranches()
        ->with('order')
        ->get()
        ->filter(fn (Payment $payment): bool => $payment->amount !== $payment->order->grand_total + $payment->fee_amount);

    expect($drifted)->toBeEmpty();
});

it('leaves a COD order processing with the money still outstanding (ADR-040)', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(OrderDemoSeeder::class);

    $cod = Payment::acrossBranches()
        ->where('method_type', PaymentMethodType::Cod)
        ->with('order')
        ->get();

    expect($cod)->not->toBeEmpty()
        ->and($cod->every(fn (Payment $payment): bool => $payment->order->payment_status === PaymentStatus::Pending
            && $payment->order->status !== OrderStatus::PendingPayment))
        ->toBeTrue();
});

it('never leaves an order with two live asks for the same money', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(OrderDemoSeeder::class);

    $doubled = Payment::acrossBranches()
        ->open()
        ->selectRaw('order_id, count(*) as total')
        ->groupBy('order_id')
        ->having('total', '>', 1)
        ->get();

    expect($doubled)->toBeEmpty();
});

it('adds nothing on a second run', function () {
    $this->seed(OrderDemoSeeder::class);
    $before = Order::acrossBranches()->count();

    $this->seed(OrderDemoSeeder::class);

    expect(Order::acrossBranches()->count())->toBe($before);
});

it('leaves the carts it used empty', function () {
    $this->seed(OrderDemoSeeder::class);

    expect(CartItem::query()->count())->toBe(0);
});
