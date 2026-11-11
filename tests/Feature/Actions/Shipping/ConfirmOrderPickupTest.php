<?php

use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Shipping\ConfirmOrderPickup;
use App\Enums\OrderStatus;
use App\Exceptions\ShippingFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderReadyForPickupNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Click & Collect, from the shelf to the counter (P2-E2-T7).
 *
 * A flow with no courier in it: the branch marks the order ready, which issues
 * a code and tells the customer; the customer turns up and reads the code out;
 * the till checks it and completes the order.
 *
 * The code is the one thing standing between a stranger and somebody else's
 * parcel, which is why it is issued only when the goods are actually waiting,
 * compared with `hash_equals`, and waived only on a named actor's authority.
 */

beforeEach(function () {
    $this->action = app(ConfirmOrderPickup::class);
    $this->transition = app(TransitionOrderStatus::class);
    $this->branch = Branch::factory()->default()->create();
    $this->staff = User::factory()->kasir($this->branch)->create();
    $this->customer = User::factory()->customer()->create();
});

describe('issuing the code', function () {
    it('issues one when the goods start waiting, and tells the customer', function () {
        Notification::fake();

        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->pickup()->processing()->create();

        $ready = $this->transition->handle($order, OrderStatus::ReadyForPickup, $this->staff);

        expect($ready->pickup_code)->not->toBeNull()
            ->and(mb_strlen((string) $ready->pickup_code))->toBe(6)
            // No `0`/`O` or `1`/`I`: it is read aloud over a counter.
            ->and($ready->pickup_code)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/')
            ->and($ready->ready_at)->not->toBeNull();

        Notification::assertSentTo($this->customer, OrderReadyForPickupNotification::class);
    });

    it('issues no code for an order that is being delivered', function () {
        Notification::fake();

        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->processing()->create();

        $shipped = $this->transition->handle($order, OrderStatus::Shipped, $this->staff);

        expect($shipped->pickup_code)->toBeNull();
        Notification::assertNothingSent();
    });

    it('notifies nobody for a walk-in order that has no account behind it', function () {
        Notification::fake();

        $order = Order::factory()->forBranch($this->branch)->guest()->pickup()->processing()->create();

        $this->transition->handle($order, OrderStatus::ReadyForPickup, $this->staff);

        Notification::assertNothingSent();
    });
});

describe('the handover', function () {
    it('completes the order when the code matches', function () {
        $order = Order::factory()->forBranch($this->branch)->forCustomer($this->customer)
            ->readyForPickup('K7M2PQ')->create();

        $completed = $this->action->handle($order, 'K7M2PQ', $this->staff);

        expect($completed->status)->toBe(OrderStatus::Completed)
            ->and($completed->completed_at)->not->toBeNull();
    });

    it('accepts the code as it was read off a phone and typed at a counter', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        $completed = $this->action->handle($order, ' k7m2pq ', $this->staff);

        expect($completed->status)->toBe(OrderStatus::Completed);
    });

    it('says so in the order timeline', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        $this->action->handle($order, 'K7M2PQ', $this->staff);

        expect($order->fresh()->statusHistories()->latest('id')->first()->note)
            ->toContain('kode pengambilan sesuai');
    });
});

describe('what it refuses', function () {
    it('refuses a code that does not match, and completes nothing', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        expect(fn () => $this->action->handle($order, 'WRONG1', $this->staff))
            ->toThrow(ShippingFailedException::class, 'tidak cocok');

        expect($order->fresh()->status)->toBe(OrderStatus::ReadyForPickup);
    });

    it('refuses an order whose goods are not waiting yet', function () {
        $order = Order::factory()->forBranch($this->branch)->pickup()->processing()->create();

        expect(fn () => $this->action->handle($order, 'K7M2PQ', $this->staff))
            ->toThrow(ShippingFailedException::class, 'belum menunggu diambil');
    });

    it('refuses an order that is being delivered', function () {
        $order = Order::factory()->forBranch($this->branch)->processing()->create();

        expect(fn () => $this->action->handle($order, 'K7M2PQ', $this->staff))
            ->toThrow(ShippingFailedException::class, 'dikirim ke alamat pelanggan');
    });

    it('refuses every code on an order that was never issued one', function () {
        $order = Order::factory()->forBranch($this->branch)->pickup()
            ->status(OrderStatus::ReadyForPickup)->create(['pickup_code' => null]);

        expect(fn () => $this->action->handle($order, 'K7M2PQ', $this->staff))
            ->toThrow(ShippingFailedException::class, 'tidak cocok');
    });
});

describe('waiving the code', function () {
    it('hands the goods over on the staff member authority', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        $completed = $this->action->handle($order, null, $this->staff, override: true);

        expect($completed->status)->toBe(OrderStatus::Completed);
    });

    it('records that the code was waived, which is the sentence a dispute needs', function () {
        $order = Order::factory()->forBranch($this->branch)->readyForPickup('K7M2PQ')->create();

        $this->action->handle($order, null, $this->staff, override: true, note: 'KTP diperiksa.');

        $history = $order->fresh()->statusHistories()->latest('id')->first();

        expect($history->note)->toContain('tanpa kode pengambilan')
            ->toContain('KTP diperiksa.')
            ->and($history->changed_by)->toBe($this->staff->getKey());
    });
});
