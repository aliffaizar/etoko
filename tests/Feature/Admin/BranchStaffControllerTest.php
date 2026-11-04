<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

/*
 * Staffing a branch from its own screen (P1-E2-T5).
 */

it('moves an existing staff account into the branch', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertRedirect();

    expect($staff->refresh()->branch_id)->toBe($branch->id);
});

it('can change the role as it assigns', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::StaffGudang->value,
        ]);

    expect($staff->refresh())
        ->branch_id->toBe($branch->id)
        ->and($staff->hasAppRole(UserRole::StaffGudang))->toBeTrue();
});

it('refuses an account that is already in the branch', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertSessionHasErrors('user_id');
});

it('refuses a customer account', function () {
    $branch = Branch::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $customer->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertSessionHasErrors('user_id');

    expect($customer->refresh()->branch_id)->toBeNull();
});

it('refuses a central account, which has no branch to be assigned to', function () {
    $branch = Branch::factory()->create();
    $central = User::factory()->adminPusat()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $central->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertSessionHasErrors('user_id');

    expect($central->refresh()->branch_id)->toBeNull();
});

it('refuses a role that is not bound to a branch', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::AdminPusat->value,
        ])
        ->assertSessionHasErrors('role');

    expect($staff->refresh()->branch_id)->not->toBe($branch->id);
});

it('refuses a super admin account', function () {
    $branch = Branch::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $superAdmin->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertSessionHasErrors('user_id');

    expect($superAdmin->refresh()->branch_id)->toBeNull();
});

it('never offers the acting user themselves as a candidate', function () {
    $branch = Branch::factory()->create();
    // A branch role, so the account would otherwise qualify as assignable.
    $actor = User::factory()->adminCabang()->create();

    $actor->givePermissionTo(['users.update']);

    $this->actingAs($actor)
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $actor->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertSessionHasErrors('user_id');

    expect($actor->refresh()->branch_id)->not->toBe($branch->id);
});

it('forbids a branch admin, who cannot reach staff of another branch', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertForbidden();

    expect($staff->refresh()->branch_id)->not->toBe($branch->id);
});

it('forbids a kasir outright', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();

    $this->actingAs(User::factory()->kasir($branch)->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::Kasir->value,
        ])
        ->assertForbidden();
});

it('keeps the account verified and active as it moves', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->kasir()->create();
    $verifiedAt = $staff->email_verified_at;

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.staff.store', $branch), [
            'user_id' => $staff->id,
            'role' => UserRole::Kasir->value,
        ]);

    expect($staff->refresh())
        ->is_active->toBeTrue()
        ->and($staff->email_verified_at?->toIso8601String())->toBe($verifiedAt?->toIso8601String());
});

it('prefills the branch on the new staff form when the branch screen links there', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.users.create', ['branch_id' => $branch->id]))
        ->assertInertia(fn ($page) => $page->where('prefill.branch_id', $branch->id));
});

it('ignores a prefilled branch the actor may not assign', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.users.create', ['branch_id' => $other->id]))
        ->assertInertia(fn ($page) => $page->where('prefill.branch_id', null));
});
