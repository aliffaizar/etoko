<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;

/*
 * CategoryPolicy (P1-E3-T2).
 *
 * BR-1: categories are managed centrally, so every write demands a central
 * actor outright and not merely the permission.
 */

it('lets a branch admin read the tree', function () {
    $actor = User::factory()->adminCabang()->create();

    expect($actor->can('viewAny', Category::class))->toBeTrue()
        ->and($actor->can('view', Category::factory()->create()))->toBeTrue();
});

it('refuses roles the matrix gives no categories.view', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();

    expect($actor->can('viewAny', Category::class))->toBeFalse()
        ->and($actor->can('view', Category::factory()->create()))->toBeFalse();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('refuses a customer', function () {
    $actor = User::factory()->customer()->create();

    expect($actor->can('viewAny', Category::class))->toBeFalse()
        ->and($actor->can('create', Category::class))->toBeFalse();
});

it('lets central staff manage the tree', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create();
    $category = Category::factory()->create();

    expect($actor->can('create', Category::class))->toBeTrue()
        ->and($actor->can('update', $category))->toBeTrue()
        ->and($actor->can('delete', $category))->toBeTrue();
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('refuses a branch admin every write', function () {
    $actor = User::factory()->adminCabang()->create();
    $category = Category::factory()->create();

    expect($actor->can('create', Category::class))->toBeFalse()
        ->and($actor->can('update', $category))->toBeFalse()
        ->and($actor->can('delete', $category))->toBeFalse();
});

it('still refuses a branch admin who was granted the write permissions by hand', function () {
    $actor = User::factory()->adminCabang()->create();
    $actor->givePermissionTo(['categories.create', 'categories.update', 'categories.delete']);
    $actor->refresh();

    $category = Category::factory()->create();

    expect($actor->can('create', Category::class))->toBeFalse()
        ->and($actor->can('update', $category))->toBeFalse()
        ->and($actor->can('delete', $category))->toBeFalse();
});
