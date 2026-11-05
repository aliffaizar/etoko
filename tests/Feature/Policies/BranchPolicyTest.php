<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

/*
 * Branch scoping (P1-E2-T7, BR-3).
 *
 * These tests run against the real permission baseline from config/rbac.php, so
 * widening a role there shows up here.
 */

it('lets central staff manage every branch', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create();
    $branch = Branch::factory()->create();

    expect($actor->can('viewAny', Branch::class))->toBeTrue()
        ->and($actor->can('view', $branch))->toBeTrue()
        ->and($actor->can('create', Branch::class))->toBeTrue()
        ->and($actor->can('update', $branch))->toBeTrue()
        ->and($actor->can('delete', $branch))->toBeTrue()
        ->and($actor->can('manageWarehouses', $branch))->toBeTrue()
        ->and($actor->can('assignStaff', $branch))->toBeTrue();
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('lets a branch admin manage their own branch', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    expect($actor->can('view', $branch))->toBeTrue()
        ->and($actor->can('update', $branch))->toBeTrue()
        ->and($actor->can('manageWarehouses', $branch))->toBeTrue();
});

it('keeps a branch admin away from another branch', function () {
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang()->create();

    expect($actor->can('view', $other))->toBeFalse()
        ->and($actor->can('update', $other))->toBeFalse()
        ->and($actor->can('manageWarehouses', $other))->toBeFalse();
});

it('does not let a branch admin create or delete branches', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    expect($actor->can('create', Branch::class))->toBeFalse()
        ->and($actor->can('delete', $branch))->toBeFalse();
});

it('refuses to let a branch-bound actor delete their own branch even when granted the permission', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $actor->givePermissionTo('branches.delete');

    expect($actor->fresh()?->can('delete', $branch))->toBeFalse();
});

it('does not let a branch admin pull staff in from another branch', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    expect($actor->can('assignStaff', $branch))->toBeFalse();
});

it('refuses warehouse and staff roles without the matching permission', function (UserRole $role) {
    $branch = Branch::factory()->create();
    $actor = User::factory()->withRole($role)->forBranch($branch)->create();

    expect($actor->can('viewAny', Branch::class))->toBeFalse()
        ->and($actor->can('update', $branch))->toBeFalse()
        ->and($actor->can('manageWarehouses', $branch))->toBeFalse();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('refuses customers outright', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->customer()->create();

    expect($actor->can('viewAny', Branch::class))->toBeFalse()
        ->and($actor->can('view', $branch))->toBeFalse()
        ->and($actor->can('update', $branch))->toBeFalse();
});

it('refuses a branch admin whose account has no branch assigned', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang()->create(['branch_id' => null]);

    expect($actor->can('update', $branch))->toBeFalse();
});

/*
 * Stock is the branch's own business, unlike the catalogue (P1-E4-T3).
 */

it('lets a branch admin and warehouse staff adjust their own stock', function (UserRole $role) {
    $branch = Branch::factory()->create();
    $actor = User::factory()->withRole($role)->forBranch($branch)->create();

    expect($actor->can('viewStock', $branch))->toBeTrue()
        ->and($actor->can('adjustStock', $branch))->toBeTrue();
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
]);

it('lets a cashier read stock without adjusting it', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->kasir($branch)->create();

    expect($actor->can('viewStock', $branch))->toBeTrue()
        ->and($actor->can('adjustStock', $branch))->toBeFalse();
});

it('keeps a branch account out of another branch stock', function () {
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();
    $actor = User::factory()->staffGudang($mine)->create();

    expect($actor->can('viewStock', $theirs))->toBeFalse()
        ->and($actor->can('adjustStock', $theirs))->toBeFalse();
});

it('lets central staff adjust the stock of any branch', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminPusat()->create();

    expect($actor->can('viewStock', $branch))->toBeTrue()
        ->and($actor->can('adjustStock', $branch))->toBeTrue();
});

it('refuses customers any sight of stock', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->customer()->create();

    expect($actor->can('viewStock', $branch))->toBeFalse()
        ->and($actor->can('adjustStock', $branch))->toBeFalse();
});
