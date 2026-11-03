<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;

/*
 * UserPolicy (P1-E1-T6).
 *
 * The policy is exercised directly here so the branch scope and the role
 * ceiling are pinned independently of any endpoint. The HTTP side is covered by
 * Admin/UserControllerTest.
 */

beforeEach(function () {
    // The RBAC baseline is installed once per process by Tests\TestCase::$seeder.
    $this->policy = new UserPolicy;
});

it('lets central staff reach any non super admin account', function () {
    $actor = User::factory()->adminPusat()->create();
    $target = User::factory()->kasir()->create();

    expect($this->policy->view($actor, $target))->toBeTrue()
        ->and($this->policy->update($actor, $target))->toBeTrue()
        ->and($this->policy->delete($actor, $target))->toBeTrue();
});

it('keeps a branch admin inside their own branch', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $own = User::factory()->kasir($branch)->create();
    $stranger = User::factory()->kasir()->create();

    expect($this->policy->view($actor, $own))->toBeTrue()
        ->and($this->policy->update($actor, $own))->toBeTrue()
        ->and($this->policy->view($actor, $stranger))->toBeFalse()
        ->and($this->policy->update($actor, $stranger))->toBeFalse();
});

it('keeps a branch admin away from central accounts', function () {
    $actor = User::factory()->adminCabang()->create();
    $central = User::factory()->adminPusat()->create();

    expect($this->policy->view($actor, $central))->toBeFalse()
        ->and($this->policy->update($actor, $central))->toBeFalse();
});

it('denies a branch admin the delete ability they have no permission for', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();
    $own = User::factory()->kasir($branch)->create();

    expect($this->policy->delete($actor, $own))->toBeFalse();
});

it('shields a super admin account from every other role', function (UserRole $role) {
    $branch = Branch::factory()->create();
    $actor = User::factory()->withRole($role)->forBranch($branch)->create();
    $superAdmin = User::factory()->superAdmin()->create();

    expect($this->policy->view($actor, $superAdmin))->toBeFalse()
        ->and($this->policy->update($actor, $superAdmin))->toBeFalse()
        ->and($this->policy->delete($actor, $superAdmin))->toBeFalse();
})->with([
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
]);

it('denies every ability to roles without the user permissions', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();
    $target = User::factory()->kasir($actor->branch_id)->create();

    expect($this->policy->viewAny($actor))->toBeFalse()
        ->and($this->policy->view($actor, $target))->toBeFalse()
        ->and($this->policy->create($actor))->toBeFalse()
        ->and($this->policy->update($actor, $target))->toBeFalse();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('reports the roles each actor may grant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $adminPusat = User::factory()->adminPusat()->create();
    $adminCabang = User::factory()->adminCabang()->create();
    $kasir = User::factory()->kasir()->create();

    expect($this->policy->grantableRoles($superAdmin))->toContain(UserRole::SuperAdmin->value)
        ->and($this->policy->grantableRoles($adminPusat))->not->toContain(UserRole::SuperAdmin->value)
        ->and($this->policy->grantableRoles($adminPusat))->toContain(UserRole::AdminPusat->value)
        ->and($this->policy->grantableRoles($adminCabang))->toBe([
            UserRole::AdminCabang->value,
            UserRole::StaffGudang->value,
            UserRole::Kasir->value,
        ])
        ->and($this->policy->grantableRoles($kasir))->toBe([]);
});

it('refuses the create ability to an actor with no grantable role', function () {
    $actor = User::factory()->kasir()->create();

    expect($this->policy->create($actor))->toBeFalse();
});
