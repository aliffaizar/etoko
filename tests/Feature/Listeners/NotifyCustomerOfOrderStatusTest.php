<?php

use App\Actions\Order\ReserveStock;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Listeners\IssueInvoiceForPaidOrder;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderReadyForPickupNotification;
use App\Notifications\OrderStatusNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Telling the customer where their order has got to (P2-E6-T3, T5).
 *
 * Every move goes through `TransitionOrderStatus`, which announces it after
 * commit; the listener decides who hears what, and the message itself goes
 * through the queue.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->staff = User::factory()->adminCabang($this->branch)->create();
    $this->transition = app(TransitionOrderStatus::class);
});

/**
 * An unpaid order of the test customer holding stock, ready to be paid.
 */
function unpaidCustomerOrder(): Order
{
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(5)->create();

    $order = Order::factory()->forBranch(test()->branch)->forCustomer(test()->customer)->create();
    OrderItem::factory()->forOrder($order)->forVariant($variant)->quantity(1)->create();
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => 1]]);

    return $order->fresh();
}

/**
 * @return array<int, string>
 */
function mailLinesFor(OrderStatusNotification $notification): array
{
    return $notification->toMail(test()->customer)->introLines;
}

it('tells the customer each milestone they are waiting on', function (OrderStatus $from, OrderStatus $to) {
    Notification::fake();
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->status($from)->create();

    $this->transition->handle($order, $to, $this->staff);

    Notification::assertSentTo(
        $this->customer,
        OrderStatusNotification::class,
        fn (OrderStatusNotification $notification): bool => $notification->status === $to,
    );
})->with([
    'shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
    'completed' => [OrderStatus::Delivered, OrderStatus::Completed],
    'cancelled' => [OrderStatus::Processing, OrderStatus::Cancelled],
]);

it('says the payment arrived when an order is paid for', function () {
    Notification::fake();
    $order = unpaidCustomerOrder();

    $this->transition->handle($order, OrderStatus::Paid);

    Notification::assertSentTo($this->customer, OrderStatusNotification::class, function (OrderStatusNotification $notification) use ($order): bool {
        $mail = $notification->toMail($this->customer);

        return $notification->status === OrderStatus::Paid
            && $mail->subject === "Pembayaran pesanan {$order->order_number} diterima"
            && in_array('Invoice pesanan ini dapat diunduh dari halaman pesanan.', $mail->outroLines, strict: true);
    });
});

it('asks for cash at the door rather than thanking for a COD payment', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()
        ->create(['payment_status' => PaymentStatus::Pending, 'grand_total' => 150_000]);

    $lines = mailLinesFor(new OrderStatusNotification($order, OrderStatus::Paid));

    expect($lines[1])->toBe('Siapkan pembayaran tunai Rp 150.000 saat kurir tiba.');
});

it('names the courier and tracking number once shipped', function () {
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->status(OrderStatus::Shipped)->create();
    Shipment::factory()->forOrder($order)->create([
        'courier_name' => 'JNE',
        'service_name' => 'REG',
        'tracking_number' => 'JNE123456',
    ]);

    $lines = mailLinesFor(new OrderStatusNotification($order->fresh(), OrderStatus::Shipped));

    expect($lines)->toContain('Kurir: JNE REG, nomor resi JNE123456.');
});

it('promises a refund only when a cancelled order had been paid for', function () {
    $unpaid = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->cancelled('Stok habis.')->create();
    $paid = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->cancelled('Stok habis.')
        ->create(['paid_at' => now()]);

    expect(mailLinesFor(new OrderStatusNotification($unpaid, OrderStatus::Cancelled)))->toHaveCount(1)
        ->and(mailLinesFor(new OrderStatusNotification($paid, OrderStatus::Cancelled))[1])->toContain('Pengembalian dana');
});

it('says nothing about moves that are the branch own business', function () {
    Notification::fake();
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->paid()->create();

    $this->transition->handle($order, OrderStatus::Processing, $this->staff);

    Notification::assertNotSentTo($this->customer, OrderStatusNotification::class);
});

it('keeps sending the pickup message for Click & Collect, not the generic one', function () {
    Notification::fake();
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->pickup()->processing()->create();

    $this->transition->handle($order, OrderStatus::ReadyForPickup, $this->staff);

    Notification::assertSentTo($this->customer, OrderReadyForPickupNotification::class);
    Notification::assertNotSentTo($this->customer, OrderStatusNotification::class);
});

it('notifies nobody for a walk-in order with no account behind it', function () {
    Notification::fake();
    $order = Order::factory()->forBranch($this->branch)->guest()->processing()->create();

    $this->transition->handle($order, OrderStatus::Shipped, $this->staff);

    Notification::assertNothingSent();
});

it('announces nothing for a transition that rolls back', function () {
    Notification::fake();
    $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)->processing()->create();

    try {
        DB::transaction(function () use ($order): void {
            $this->transition->handle($order, OrderStatus::Shipped, $this->staff);

            throw new RuntimeException('Something after the move failed.');
        });
    } catch (RuntimeException) {
        // The move was undone with it.
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
    Notification::assertNothingSent();
});

it('sends the message and issues the invoice through the queue', function () {
    Queue::fake();
    $order = unpaidCustomerOrder();

    $this->transition->handle($order, OrderStatus::Paid);

    Queue::assertPushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job): bool => $job->notification instanceof OrderStatusNotification,
    );
    Queue::assertPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job): bool => $job->class === IssueInvoiceForPaidOrder::class,
    );
});
