<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Voucher;

/*
 * VoucherPolicy (P2-E5-T2).
 *
 * Marketing is central (docs/04-rbac.md): `vouchers.manage` and a central
 * actor, both.
 */

it('lets central staff manage vouchers', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create();
    $voucher = Voucher::factory()->create();

    expect($actor->can('viewAny', Voucher::class))->toBeTrue()
        ->and($actor->can('create', Voucher::class))->toBeTrue()
        ->and($actor->can('update', $voucher))->toBeTrue()
        ->and($actor->can('delete', $voucher))->toBeTrue();
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('refuses every branch role and the customer', function (UserRole $role) {
    $actor = $role === UserRole::Customer
        ? User::factory()->customer()->create()
        : User::factory()->withRole($role)->forBranch()->create();

    expect($actor->can('viewAny', Voucher::class))->toBeFalse()
        ->and($actor->can('create', Voucher::class))->toBeFalse();
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
    'customer' => [UserRole::Customer],
]);

it('still refuses a branch admin who was given vouchers.manage on the Role screen', function () {
    $actor = User::factory()->adminCabang()->create();
    $actor->givePermissionTo('vouchers.manage');

    expect($actor->can('create', Voucher::class))->toBeFalse()
        ->and($actor->can('update', Voucher::factory()->create()))->toBeFalse();
});
