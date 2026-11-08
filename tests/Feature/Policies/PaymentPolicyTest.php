<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/*
 * Who may read and settle a payment (P2-E1-T4, docs/04-rbac.md).
 *
 * The policy is the **second** line: `payments` carries the branch global
 * scope, so another branch's payment is normally not found at all (ADR-027).
 * These tests therefore reach the records across branches on purpose, to check
 * what the policy says when it is actually consulted.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->payment = Payment::factory()
        ->forOrder(Order::factory()->forBranch($this->branch)->create())
        ->create();
    $this->otherPayment = Payment::factory()
        ->forOrder(Order::factory()->forBranch($this->otherBranch)->create())
        ->create();
});

describe('reading', function () {
    it('lets central staff read every branch payment', function () {
        $central = User::factory()->adminPusat()->create();

        expect($central->can('viewAny', Payment::class))->toBeTrue()
            ->and($central->can('view', $this->payment))->toBeTrue()
            ->and($central->can('view', $this->otherPayment))->toBeTrue();
    });

    it('keeps a branch admin to their own branch (BR-3)', function () {
        $admin = User::factory()->adminCabang($this->branch)->create();

        expect($admin->can('view', $this->payment))->toBeTrue()
            ->and($admin->can('view', $this->otherPayment))->toBeFalse();
    });

    it('lets a cashier read, because serving a customer means knowing if they paid', function () {
        $cashier = User::factory()->kasir($this->branch)->create();

        expect($cashier->can('viewAny', Payment::class))->toBeTrue()
            ->and($cashier->can('view', $this->payment))->toBeTrue();
    });

    it('gives a customer nothing at all', function () {
        $customer = User::factory()->customer()->create();

        expect($customer->can('viewAny', Payment::class))->toBeFalse()
            ->and($customer->can('view', $this->payment))->toBeFalse();
    });

    it('lets warehouse staff nowhere near the money', function () {
        $warehouse = User::factory()->staffGudang($this->branch)->create();

        expect($warehouse->can('viewAny', Payment::class))->toBeFalse();
    });
});

describe('settling', function () {
    it('lets a branch admin settle their own branch payments', function () {
        $admin = User::factory()->adminCabang($this->branch)->create();

        expect($admin->can('settle', $this->payment))->toBeTrue()
            ->and($admin->can('settle', $this->otherPayment))->toBeFalse();
    });

    it('refuses a cashier, who holds payments.view but not payments.verify', function () {
        $cashier = User::factory()->kasir($this->branch)->create();

        expect($cashier->can('settle', $this->payment))->toBeFalse();
    });

    it('refuses an attempt that has already been decided', function () {
        $admin = User::factory()->adminCabang($this->branch)->create();
        $settled = Payment::factory()
            ->forOrder(Order::factory()->forBranch($this->branch)->create())
            ->success()
            ->create();

        expect($admin->can('settle', $settled))->toBeFalse();
    });
});

describe('refunding', function () {
    it('is central only, even for a branch admin who holds the permission', function () {
        $central = User::factory()->adminPusat()->create();
        $admin = User::factory()->adminCabang($this->branch)->create();
        $admin->givePermissionTo('payments.refund');

        expect($central->can('refund', $this->payment))->toBeTrue()
            ->and($admin->fresh()->can('refund', $this->payment))->toBeFalse();
    });
});

it('lets a super admin past the policy entirely', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->can('view', $this->otherPayment))->toBeTrue()
        ->and($superAdmin->can('settle', $this->otherPayment))->toBeTrue()
        ->and($superAdmin->can('refund', $this->otherPayment))->toBeTrue();
});
