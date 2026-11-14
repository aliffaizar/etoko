<?php

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAwaitingBranchNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Telling a branch that a paid order is waiting to be packed (P2-E6-T4).
 */

beforeEach(function () {
    Notification::fake();

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->order = Order::factory()->forBranch($this->branch)->paid()->create();
});

it('tells the branch staff who move orders along or pack them, and nobody else', function () {
    $admin = User::factory()->adminCabang($this->branch)->create();
    $warehouse = User::factory()->staffGudang($this->branch)->create();
    $cashier = User::factory()->kasir($this->branch)->create();
    $inactive = User::factory()->adminCabang($this->branch)->inactive()->create();
    $elsewhere = User::factory()->adminCabang($this->otherBranch)->create();
    $central = User::factory()->adminPusat()->create();

    OrderStatusChanged::dispatch($this->order, OrderStatus::PendingPayment, OrderStatus::Paid);

    Notification::assertSentTo($admin, OrderAwaitingBranchNotification::class);
    Notification::assertSentTo($warehouse, OrderAwaitingBranchNotification::class);
    foreach ([$cashier, $inactive, $elsewhere, $central] as $nobody) {
        Notification::assertNotSentTo($nobody, OrderAwaitingBranchNotification::class);
    }
});

it('does not tell the staff member who confirmed the payment', function () {
    $confirmer = User::factory()->adminCabang($this->branch)->create();
    $colleague = User::factory()->adminCabang($this->branch)->create();

    OrderStatusChanged::dispatch($this->order, OrderStatus::PendingPayment, OrderStatus::Paid, $confirmer);

    Notification::assertNotSentTo($confirmer, OrderAwaitingBranchNotification::class);
    Notification::assertSentTo($colleague, OrderAwaitingBranchNotification::class);
});

it('stays quiet for every status other than paid', function () {
    User::factory()->adminCabang($this->branch)->create();

    OrderStatusChanged::dispatch($this->order, OrderStatus::Paid, OrderStatus::Processing);

    Notification::assertNothingSentTo(User::where('branch_id', $this->branch->id)->get());
});

it('stores what the bell needs to say and link to', function () {
    $admin = User::factory()->adminCabang($this->branch)->create();
    $this->order->update(['customer_name' => 'Sari', 'grand_total' => 80_000]);

    $data = (new OrderAwaitingBranchNotification($this->order->fresh()))->toDatabase($admin);

    expect($data)->toMatchArray([
        'type' => 'order_awaiting_branch',
        'order_id' => $this->order->id,
        'message' => "Pesanan {$this->order->order_number} (Sari, Rp 80.000) sudah dibayar dan menunggu diproses.",
    ]);
});
