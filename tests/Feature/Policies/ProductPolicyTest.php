<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

/*
 * ProductPolicy (P1-E3-T5).
 *
 * BR-1 names this policy: products are managed centrally, so every write
 * demands a central actor outright and not merely the permission.
 */

it('lets every panel role read the catalogue', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();

    expect($actor->can('viewAny', Product::class))->toBeTrue()
        ->and($actor->can('view', Product::factory()->create()))->toBeTrue();
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('refuses a customer', function () {
    $actor = User::factory()->customer()->create();

    expect($actor->can('viewAny', Product::class))->toBeFalse()
        ->and($actor->can('create', Product::class))->toBeFalse();
});

it('lets central staff manage the catalogue', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create();
    $product = Product::factory()->create();

    expect($actor->can('create', Product::class))->toBeTrue()
        ->and($actor->can('update', $product))->toBeTrue()
        ->and($actor->can('delete', $product))->toBeTrue();
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('refuses every branch role every write', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();
    $product = Product::factory()->create();

    expect($actor->can('create', Product::class))->toBeFalse()
        ->and($actor->can('update', $product))->toBeFalse()
        ->and($actor->can('delete', $product))->toBeFalse();
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('still refuses a branch admin who was granted the write permissions by hand', function () {
    $actor = User::factory()->adminCabang()->create();
    $actor->givePermissionTo(['products.create', 'products.update', 'products.delete']);
    $actor->refresh();

    $product = Product::factory()->create();

    expect($actor->can('create', Product::class))->toBeFalse()
        ->and($actor->can('update', $product))->toBeFalse()
        ->and($actor->can('delete', $product))->toBeFalse();
});
