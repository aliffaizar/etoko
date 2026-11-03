<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;

/*
 * Role & Permission screen (P1-E1-T5). Only a Super Admin holds `roles.*`.
 */

it('redirects guests to the login page', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
});

it('lists every role for a super admin', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/index')
            ->has('roles', count(UserRole::cases()))
            ->where('roles.0.name', UserRole::SuperAdmin->value)
            ->where('roles.0.is_editable', false)
            ->where('permissionTotal', count(Arr::flatten(config('rbac.permissions')))));
});

it('counts the users holding each role', function () {
    User::factory()->count(3)->kasir()->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.roles.index'))
        ->assertInertia(function ($page) {
            $roles = collect($page->toArray()['props']['roles']);
            $kasir = $roles->firstWhere('name', UserRole::Kasir->value);

            expect($kasir['user_count'])->toBe(3);
        });
});

it('forbids every role but super admin', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();

    $this->actingAs($actor)->get(route('admin.roles.index'))->assertForbidden();
})->with([
    'admin pusat' => [UserRole::AdminPusat],
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('shows the permission catalogue grouped when editing a role', function () {
    $role = Role::findByName(UserRole::Kasir->value, 'web');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.roles.edit', $role))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/edit')
            ->where('role.name', UserRole::Kasir->value)
            ->where('role.label', 'Kasir')
            ->has('role.permissions')
            ->has('groups', count(config('rbac.permissions')))
            ->has('groups.0.permissions'));
});

it('refuses to edit the super admin role', function () {
    $role = Role::findByName(UserRole::SuperAdmin->value, 'web');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.roles.edit', $role))
        ->assertForbidden();
});

it('replaces the permissions of a role', function () {
    $role = Role::findByName(UserRole::Kasir->value, 'web');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.roles.update', $role), [
            'permissions' => ['orders.view', 'payments.view'],
        ])
        ->assertRedirect(route('admin.roles.edit', $role));

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['orders.view', 'payments.view']);
});

it('accepts an empty permission set', function () {
    $role = Role::findByName(UserRole::Kasir->value, 'web');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.roles.update', $role), [])
        ->assertRedirect();

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('rejects a permission that is not in the catalogue', function () {
    $role = Role::findByName(UserRole::Kasir->value, 'web');
    $before = $role->permissions->pluck('name')->sort()->values()->all();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.roles.update', $role), [
            'permissions' => ['orders.view', 'users.become_root'],
        ])
        ->assertSessionHasErrors('permissions.1');

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($before);
});

it('refuses to change the super admin role permissions', function () {
    $role = Role::findByName(UserRole::SuperAdmin->value, 'web');
    $before = $role->permissions->count();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.roles.update', $role), ['permissions' => ['orders.view']])
        ->assertForbidden();

    expect($role->fresh()->permissions->count())->toBe($before);
});

it('applies a permission change to the users holding the role immediately', function () {
    $role = Role::findByName(UserRole::Kasir->value, 'web');
    $kasir = User::factory()->kasir()->create();

    expect($kasir->can('orders.view'))->toBeTrue();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.roles.update', $role), ['permissions' => ['pos.operate']]);

    expect($kasir->fresh()->can('orders.view'))->toBeFalse()
        ->and($kasir->fresh()->can('pos.operate'))->toBeTrue();
});
