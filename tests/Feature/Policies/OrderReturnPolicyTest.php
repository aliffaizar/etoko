<?php

use App\Enums\ReturnStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\User;

/*
 * Who may decide a return, and who may send money back (P2-E4-T3, T4).
 *
 * The two policies are tested together because the line between them is the
 * point: a branch admin takes goods back off a customer without being able to
 * pay for them, and that line is drawn with `isCentral()` rather than with a
 * permission, so granting `refunds.process` on the Role screen cannot move it
 * (ADR-049).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->central = User::factory()->adminPusat()->create();
    $this->branchAdmin = User::factory()->adminCabang($this->branch)->create();
    $this->otherBranchAdmin = User::factory()->adminCabang($this->otherBranch)->create();
    $this->cashier = User::factory()->kasir($this->branch)->create();
    $this->warehouseStaff = User::factory()->staffGudang($this->branch)->create();
    $this->customer = User::factory()->customer()->create();
});

function returnAt(Branch $branch, ReturnStatus $status = ReturnStatus::Requested): OrderReturn
{
    $order = Order::factory()->forBranch($branch)->delivered()->create([
        'subtotal' => 200_000,
        'shipping_cost' => 0,
        'tax_total' => 0,
        'grand_total' => 200_000,
    ]);

    $item = OrderItem::factory()->forOrder($order)->quantity(2)->unitPrice(100_000)->create();

    $return = OrderReturn::factory()->forOrder($order->fresh())->create();
    ReturnItem::factory()->forReturn($return)->forOrderItem($item, 1)->create();

    if ($status !== ReturnStatus::Requested) {
        $return->forceFill(['status' => $status])->saveQuietly();
    }

    return $return->fresh();
}

describe('reading a return', function () {
    it('lets central and branch staff who hold returns.view read it', function () {
        $return = returnAt($this->branch);

        expect($this->central->can('view', $return))->toBeTrue()
            ->and($this->branchAdmin->can('view', $return))->toBeTrue()
            ->and($this->superAdmin->can('view', $return))->toBeTrue();
    });

    it('refuses a role that does not hold returns.view', function () {
        $return = returnAt($this->branch);

        expect($this->cashier->can('view', $return))->toBeFalse()
            ->and($this->warehouseStaff->can('view', $return))->toBeFalse()
            ->and($this->customer->can('view', $return))->toBeFalse()
            ->and($this->customer->can('viewAny', OrderReturn::class))->toBeFalse();
    });

    it('refuses another branch request even to somebody who holds the permission (BR-3)', function () {
        $return = returnAt($this->branch);

        expect($this->otherBranchAdmin->can('view', $return))->toBeFalse()
            ->and($this->otherBranchAdmin->can('decide', $return))->toBeFalse();
    });
});

describe('deciding a return', function () {
    it('lets a branch admin decide their own branch requests', function () {
        $return = returnAt($this->branch);

        expect($this->branchAdmin->can('decide', $return))->toBeTrue()
            ->and($this->central->can('decide', $return))->toBeTrue();
    });

    it('refuses warehouse staff and cashiers, who hold no returns.approve', function () {
        $return = returnAt($this->branch);

        expect($this->warehouseStaff->can('decide', $return))->toBeFalse()
            ->and($this->cashier->can('decide', $return))->toBeFalse();
    });

    it('stays true for a decided request, because whether it may move is the request answer', function () {
        $rejected = returnAt($this->branch, ReturnStatus::Rejected);

        // The policy answers "may this person decide at all"; whether *this*
        // request can still move is `OrderReturn::approvalBlockedReason()`.
        // Keeping the two apart is what lets the screen show a greyed-out
        // button with its reason rather than hiding the branch's own queue.
        expect($this->branchAdmin->can('decide', $rejected))->toBeTrue()
            ->and($rejected->approvalBlockedReason())->not->toBeNull();
    });
});

describe('sending money back', function () {
    it('lets a central actor open a refund against an order with money left', function () {
        $return = returnAt($this->branch, ReturnStatus::Received);

        expect($this->central->can('refund', $return->order))->toBeTrue()
            ->and($this->superAdmin->can('refund', $return->order))->toBeTrue();
    });

    it('refuses a branch admin even when the permission is granted', function () {
        $return = returnAt($this->branch, ReturnStatus::Received);

        $this->branchAdmin->givePermissionTo('refunds.process');
        $this->branchAdmin->refresh();

        expect($this->branchAdmin->can('refund', $return->order))->toBeFalse();
    });

    it('refuses an order that has already been refunded in full', function () {
        $return = returnAt($this->branch, ReturnStatus::Received);

        Refund::factory()->forOrder($return->order)->amount(200_000)->processed()->create();

        expect($this->central->can('refund', $return->order->fresh()))->toBeFalse();
    });

    it('lets a branch admin read a refund but not carry one out', function () {
        $return = returnAt($this->branch, ReturnStatus::Received);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->create();

        $this->branchAdmin->givePermissionTo('refunds.process');
        $this->branchAdmin->refresh();

        expect($this->branchAdmin->can('view', $refund))->toBeTrue()
            ->and($this->branchAdmin->can('process', $refund))->toBeFalse()
            ->and($this->central->can('process', $refund))->toBeTrue();
    });

    it('hides another branch refund from a branch admin while central reads every branch', function () {
        $return = returnAt($this->branch, ReturnStatus::Received);
        $refund = Refund::factory()->forReturn($return)->amount(100_000)->create();

        expect($this->otherBranchAdmin->can('view', $refund))->toBeFalse()
            ->and($this->central->can('view', $refund))->toBeTrue();
    });
});
