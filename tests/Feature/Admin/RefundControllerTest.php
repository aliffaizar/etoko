<?php

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Money going back, in the panel (P2-E4-T4).
 *
 * The permission split this file is really about: a branch admin holds
 * `returns.view` and **reads** what went back against its own orders, while
 * making a refund needs `refunds.process` and is **central** (ADR-049). The
 * listing is therefore visible to a branch and its writes are not — and
 * granting `refunds.process` on the Role screen must not change that, which is
 * why `OrderPolicy::refund` and `RefundPolicy::process` state `isCentral()`
 * explicitly rather than trusting the permission alone.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    Warehouse::factory()->for($this->branch)->default()->create();
    $this->otherBranch = Branch::factory()->create();
    Warehouse::factory()->for($this->otherBranch)->default()->create();

    $this->central = User::factory()->adminPusat()->create();
    $this->branchAdmin = User::factory()->adminCabang($this->branch)->create();
    $this->cashier = User::factory()->kasir($this->branch)->create();
});

/**
 * A delivered, paid order with a received return against one of its two units.
 *
 * The record only — no stock has moved — because these tests are about the
 * refund endpoints rather than about receiving goods.
 *
 * @return array{0: Order, 1: OrderReturn}
 */
function refundableOrder(Branch $branch): array
{
    $order = Order::factory()->forBranch($branch)->delivered()->create([
        'subtotal' => 200_000,
        'shipping_cost' => 20_000,
        'tax_total' => 0,
        'grand_total' => 220_000,
    ]);

    $item = OrderItem::factory()->forOrder($order)->quantity(2)->unitPrice(100_000)->create();

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();
    $return->forceFill(['status' => ReturnStatus::Received, 'received_at' => now()])->saveQuietly();

    return [$order->fresh(), $return->fresh()];
}

describe('the listing', function () {
    it('shows every branch refund to central staff', function () {
        [$mine] = refundableOrder($this->branch);
        [$theirs] = refundableOrder($this->otherBranch);

        Refund::factory()->forOrder($mine)->amount(50_000)->create();
        Refund::factory()->forOrder($theirs)->amount(70_000)->create();

        $this->actingAs($this->central)
            ->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/refunds/index')
                ->has('refunds.data', 2)
                ->where('is_central', true)
                ->where('can.process', true));
    });

    it('shows a branch admin only their own branch refunds, read-only (BR-3)', function () {
        [$mine] = refundableOrder($this->branch);
        [$theirs] = refundableOrder($this->otherBranch);

        $refund = Refund::factory()->forOrder($mine)->amount(50_000)->create();
        Refund::factory()->forOrder($theirs)->amount(70_000)->create();

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('refunds.data', 1)
                ->where('refunds.data.0.id', $refund->getKey())
                ->where('is_central', false)
                ->where('can.process', false));
    });

    it('sums the money promised and the money sent, separately', function () {
        [$order] = refundableOrder($this->branch);

        Refund::factory()->forOrder($order)->amount(30_000)->create();
        Refund::factory()->forOrder($order)->amount(40_000)->processed()->create();
        Refund::factory()->forOrder($order)->amount(20_000)->failed()->create();

        $this->actingAs($this->central)
            ->get(route('admin.refunds.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.pending', 1)
                ->where('summary.pending_amount', 30_000)
                ->where('summary.processed', 1)
                ->where('summary.processed_amount', 40_000)
                ->where('summary.failed', 1));
    });

    it('refuses a role without returns.view', function () {
        $this->actingAs($this->cashier)
            ->get(route('admin.refunds.index'))
            ->assertForbidden();
    });
});

describe('opening a refund', function () {
    it('lets a central actor pay money back against a received return', function () {
        [$order, $return] = refundableOrder($this->branch);

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.orders.refunds.store', $order), [
                'amount' => 100_000,
                'method' => RefundMethod::Transfer->value,
                'return_id' => $return->id,
                'account_info' => 'BCA 1234567890 a/n Budi',
            ])
            ->assertRedirect(route('admin.returns.show', $return));

        $refund = Refund::withoutGlobalScopes()->sole();

        expect($refund->amount)->toBe(100_000)
            ->and($refund->status)->toBe(RefundStatus::Pending)
            ->and($refund->return_id)->toBe($return->id)
            // Pending money has not gone anywhere.
            ->and($order->fresh()->payment_status->value)->toBe('paid');
    });

    it('refuses a branch admin even when the permission is granted (ADR-049)', function () {
        [$order, $return] = refundableOrder($this->branch);

        $this->branchAdmin->givePermissionTo('refunds.process');
        $this->branchAdmin->refresh();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.orders.refunds.store', $order), [
                'amount' => 100_000,
                'method' => RefundMethod::Transfer->value,
                'return_id' => $return->id,
                'account_info' => 'BCA 1234567890 a/n Budi',
            ])
            ->assertForbidden();

        expect(Refund::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses a cashier outright', function () {
        [$order] = refundableOrder($this->branch);

        $this->actingAs($this->cashier)
            ->post(route('admin.orders.refunds.store', $order), [
                'amount' => 10_000,
                'method' => RefundMethod::Cash->value,
            ])
            ->assertForbidden();
    });

    it('requires the bank details for a transfer', function () {
        [$order, $return] = refundableOrder($this->branch);

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.orders.refunds.store', $order), [
                'amount' => 100_000,
                'method' => RefundMethod::Transfer->value,
                'return_id' => $return->id,
            ])
            ->assertSessionHasErrors('account_info');

        expect(Refund::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses more than the order has left to give back', function () {
        [$order, $return] = refundableOrder($this->branch);

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.orders.refunds.store', $order), [
                'amount' => 300_000,
                'method' => RefundMethod::Cash->value,
                'return_id' => $return->id,
            ])
            ->assertSessionHasErrors('amount');
    });
});

describe('finishing a refund', function () {
    it('settles a pending refund and moves the order payment status', function () {
        [$order, $return] = refundableOrder($this->branch);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->create();

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.refunds.settle', $refund), ['reference' => 'TRF99'])
            ->assertRedirect();

        $refund->refresh();
        $order->refresh();

        expect($refund->status)->toBe(RefundStatus::Processed)
            ->and($refund->reference)->toBe('TRF99')
            ->and($refund->processed_by)->toBe($this->central->getKey())
            ->and($order->payment_status->value)->toBe('partially_refunded')
            // The order's own totals are untouched (ADR-047).
            ->and($order->grand_total)->toBe(220_000);
    });

    it('requires a reference to settle, because a refund has to be provable', function () {
        [, $return] = refundableOrder($this->branch);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->create();

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.refunds.settle', $refund), ['reference' => ''])
            ->assertSessionHasErrors('reference');

        expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
    });

    it('fails a pending refund and gives its amount back to the order', function () {
        [$order, $return] = refundableOrder($this->branch);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->create();

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.refunds.fail', $refund), ['reason' => 'Rekening tujuan salah.'])
            ->assertRedirect();

        expect($refund->fresh()->status)->toBe(RefundStatus::Failed)
            ->and($order->fresh()->refundableAmount())->toBe(220_000);
    });

    it('refuses a branch admin, and answers 404 for another branch refund', function () {
        [, $mine] = refundableOrder($this->branch);
        [, $theirs] = refundableOrder($this->otherBranch);

        $own = Refund::factory()->forReturn($mine)->amount(10_000)->create();
        $foreign = Refund::factory()->forReturn($theirs)->amount(10_000)->create();

        $this->branchAdmin->givePermissionTo('refunds.process');
        $this->branchAdmin->refresh();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.refunds.settle', $own), ['reference' => 'TRF1'])
            ->assertForbidden();

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.refunds.settle', $foreign), ['reference' => 'TRF1'])
            ->assertNotFound();

        expect($own->fresh()->status)->toBe(RefundStatus::Pending);
    });

    it('refuses settling a refund that has already been settled', function () {
        [, $return] = refundableOrder($this->branch);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->processed()->create();

        $this->actingAs($this->central)
            ->from(route('admin.returns.show', $return))
            ->post(route('admin.refunds.settle', $refund), ['reference' => 'TRF2'])
            ->assertSessionHasErrors('reference');
    });
});
