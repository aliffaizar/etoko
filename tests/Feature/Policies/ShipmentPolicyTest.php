<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;

/*
 * Who may read a parcel and who may send one (P2-E2-T4).
 *
 * Shipments are **not** central-only: the branch that packs the goods is the
 * branch that walks them to the courier's counter. BR-3 keeps each branch to
 * its own, and the 404 from route-model binding is the first line — these
 * refusals are the second (ADR-027).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->shipment = Shipment::factory()->forOrder(
        Order::factory()->forBranch($this->branch)->processing()->create()
    )->create();

    $this->elsewhere = Shipment::factory()->forOrder(
        Order::factory()->forBranch($this->otherBranch)->processing()->create()
    )->create();
});

it('lets central staff read and manage every branch parcel', function () {
    $central = User::factory()->adminPusat()->create();

    expect($central->can('view', $this->shipment))->toBeTrue()
        ->and($central->can('view', $this->elsewhere))->toBeTrue()
        ->and($central->can('manage', $this->elsewhere))->toBeTrue();
});

it('keeps a branch admin to their own branch parcels', function () {
    $admin = User::factory()->adminCabang($this->branch)->create();

    expect($admin->can('view', $this->shipment))->toBeTrue()
        ->and($admin->can('manage', $this->shipment))->toBeTrue()
        ->and($admin->can('view', $this->elsewhere))->toBeFalse()
        ->and($admin->can('manage', $this->elsewhere))->toBeFalse();
});

it('refuses staff who hold no shipping permission', function () {
    $cashier = User::factory()->kasir($this->branch)->create();
    $warehouse = User::factory()->staffGudang($this->branch)->create();

    expect($cashier->can('view', $this->shipment))->toBeFalse()
        ->and($cashier->can('manage', $this->shipment))->toBeFalse()
        ->and($warehouse->can('manage', $this->shipment))->toBeFalse();
});

it('refuses a customer, whose own tracking is reached through their order', function () {
    $customer = User::factory()->customer()->create();

    expect($customer->can('viewAny', Shipment::class))->toBeFalse()
        ->and($customer->can('view', $this->shipment))->toBeFalse();
});

it('lets a super admin through without consulting the policy', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->can('manage', $this->elsewhere))->toBeTrue();
});

it('refuses a branch account that has no branch to narrow by', function () {
    $stray = User::factory()->adminCabang()->create(['branch_id' => null]);

    expect($stray->can('view', $this->shipment))->toBeFalse()
        ->and($stray->can('manage', $this->shipment))->toBeFalse();
});
