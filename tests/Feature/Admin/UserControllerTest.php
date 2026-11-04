<?php

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Staff account management (P1-E1-T4).
 *
 * These tests run against the real permission baseline so a change to
 * config/rbac.php that widens access shows up here.
 */

it('redirects guests to the login page', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

it('lists staff accounts for central staff', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create(['name' => 'Aktor Pusat']);
    User::factory()->kasir()->create(['name' => 'Kasir Satu']);

    $this->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users.data', 2)
            ->has('filters')
            ->where('options.showBranchFilter', true));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('leaves customers out of the staff listing', function () {
    $actor = User::factory()->adminPusat()->create();
    User::factory()->customer()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page->has('users.data', 1));
});

it('forbids roles without the users.view permission', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();

    $this->actingAs($actor)->get(route('admin.users.index'))->assertForbidden();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

it('shows a branch admin only the staff of their own branch', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();

    $actor = User::factory()->adminCabang($branch)->create(['name' => 'Admin Cabang Ini']);
    User::factory()->kasir($branch)->create(['name' => 'Kasir Cabang Ini']);
    User::factory()->kasir($other)->create(['name' => 'Kasir Cabang Lain']);
    User::factory()->adminPusat()->create(['name' => 'Admin Pusat']);

    $this->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 2)
            ->where('options.showBranchFilter', false));
});

it('filters the listing by role, branch and status', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminPusat()->create();

    User::factory()->kasir($branch)->create(['name' => 'Kasir Aktif']);
    User::factory()->kasir($branch)->inactive()->create(['name' => 'Kasir Nonaktif']);
    User::factory()->staffGudang()->create(['name' => 'Gudang Lain']);

    $this->actingAs($actor)
        ->get(route('admin.users.index', ['role' => UserRole::Kasir->value]))
        ->assertInertia(fn ($page) => $page->has('users.data', 2));

    $this->actingAs($actor)
        ->get(route('admin.users.index', ['branch' => $branch->id, 'status' => 'inactive']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Kasir Nonaktif'));

    // Only the acting Admin Pusat has no branch; the factory gives every
    // branch role a branch of its own.
    $this->actingAs($actor)
        ->get(route('admin.users.index', ['branch' => 'central']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.branch', null));
});

it('searches the listing by name, email and phone', function () {
    $actor = User::factory()->adminPusat()->create(['name' => 'Aktor', 'email' => 'aktor@etoko.test']);
    User::factory()->kasir()->create(['name' => 'Budi Santoso', 'email' => 'budi@etoko.test', 'phone' => '081234000111']);
    User::factory()->kasir()->create(['name' => 'Citra Dewi', 'email' => 'citra@etoko.test', 'phone' => '081234000222']);

    $this->actingAs($actor)
        ->get(route('admin.users.index', ['search' => 'Budi']))
        ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.email', 'budi@etoko.test'));

    $this->actingAs($actor)
        ->get(route('admin.users.index', ['search' => '000222']))
        ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.name', 'Citra Dewi'));
});

it('rejects an unknown sort column', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.index', ['sort' => 'password']))
        ->assertSessionHasErrors('sort');
});

it('creates a staff account with its role and branch', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Dewi Kasir',
            'email' => 'dewi@etoko.test',
            'phone' => '081200001234',
            'role' => UserRole::Kasir->value,
            'branch_id' => $branch->id,
            'is_active' => '1',
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ])
        ->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'dewi@etoko.test')->firstOrFail();

    expect($created->branch_id)->toBe($branch->id)
        ->and($created->is_active)->toBeTrue()
        ->and($created->hasAppRole(UserRole::Kasir))->toBeTrue()
        ->and($created->email_verified_at)->not->toBeNull();
});

it('refuses to create an account with a role the actor cannot grant', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Calon Super Admin',
            'email' => 'calon@etoko.test',
            'role' => UserRole::SuperAdmin->value,
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ])
        ->assertSessionHasErrors('role');

    expect(User::where('email', 'calon@etoko.test')->exists())->toBeFalse();
});

it('refuses to create a branch role without a branch', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Kasir Tanpa Cabang',
            'email' => 'tanpacabang@etoko.test',
            'role' => UserRole::Kasir->value,
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ])
        ->assertSessionHasErrors('branch_id');
});

it('refuses to let a branch admin assign staff to another branch', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Kasir Cabang Lain',
            'email' => 'lain@etoko.test',
            'role' => UserRole::Kasir->value,
            'branch_id' => $other->id,
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ])
        ->assertSessionHasErrors('branch_id');

    expect(User::where('email', 'lain@etoko.test')->exists())->toBeFalse();
});

it('updates a staff account', function () {
    $actor = User::factory()->adminPusat()->create();
    $branch = Branch::factory()->create();
    $user = User::factory()->kasir($branch)->create(['name' => 'Nama Lama']);

    $this->actingAs($actor)
        ->put(route('admin.users.update', $user), [
            'name' => 'Nama Baru',
            'email' => $user->email,
            'phone' => '081299998888',
            'role' => UserRole::StaffGudang->value,
            'branch_id' => $branch->id,
            'is_active' => '1',
        ])
        ->assertRedirect(route('admin.users.edit', $user));

    $user->refresh();

    expect($user->name)->toBe('Nama Baru')
        ->and($user->hasAppRole(UserRole::StaffGudang))->toBeTrue();
});

it('clears the branch when the role becomes a central one', function () {
    $actor = User::factory()->superAdmin()->create();
    $branch = Branch::factory()->create();
    $user = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::AdminPusat->value,
            'branch_id' => $branch->id,
            'is_active' => '1',
        ])
        ->assertRedirect();

    expect($user->refresh()->branch_id)->toBeNull();
});

it('deactivates an account through the edit form checkbox', function () {
    $actor = User::factory()->adminPusat()->create();
    $branch = Branch::factory()->create();
    $user = User::factory()->kasir($branch)->create(['is_active' => true]);

    // The form posts the checkbox as "0"/"1" through a hidden field.
    $this->actingAs($actor)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::Kasir->value,
            'branch_id' => $branch->id,
            'is_active' => '0',
        ])
        ->assertRedirect();

    expect($user->refresh()->is_active)->toBeFalse();
});

it('forbids a branch admin from opening staff of another branch', function () {
    $actor = User::factory()->adminCabang()->create();
    $stranger = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.edit', $stranger))
        ->assertForbidden();
});

it('forbids a branch admin from updating staff of another branch', function () {
    $actor = User::factory()->adminCabang()->create();
    $stranger = User::factory()->kasir()->create(['name' => 'Tetap Begini']);

    $this->actingAs($actor)
        ->put(route('admin.users.update', $stranger), [
            'name' => 'Diubah Paksa',
            'email' => $stranger->email,
            'role' => UserRole::Kasir->value,
            'branch_id' => $actor->branch_id,
            'is_active' => '1',
        ])
        ->assertForbidden();

    expect($stranger->refresh()->name)->toBe('Tetap Begini');
});

it('forbids anyone but a super admin from editing a super admin account', function () {
    $actor = User::factory()->adminPusat()->create();
    $target = User::factory()->superAdmin()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.edit', $target))
        ->assertForbidden();
});

it('activates and deactivates an account', function () {
    $actor = User::factory()->adminPusat()->create();
    $user = User::factory()->kasir()->create(['is_active' => true]);

    $this->actingAs($actor)
        ->from(route('admin.users.index'))
        ->patch(route('admin.users.status.update', $user), ['is_active' => false])
        ->assertRedirect(route('admin.users.index'));

    expect($user->refresh()->is_active)->toBeFalse();

    $this->actingAs($actor)
        ->from(route('admin.users.index'))
        ->patch(route('admin.users.status.update', $user), ['is_active' => true]);

    expect($user->refresh()->is_active)->toBeTrue();
});

it('refuses to let anyone deactivate their own account', function () {
    $actor = User::factory()->superAdmin()->create(['is_active' => true]);

    $this->actingAs($actor)
        ->patch(route('admin.users.status.update', $actor), ['is_active' => false])
        ->assertForbidden();

    expect($actor->refresh()->is_active)->toBeTrue();
});

it('resets a staff password', function () {
    $actor = User::factory()->adminPusat()->create();
    $user = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->from(route('admin.users.edit', $user))
        ->put(route('admin.users.password.update', $user), [
            'password' => 'sandi-baru-kuat-1',
            'password_confirmation' => 'sandi-baru-kuat-1',
        ])
        ->assertRedirect(route('admin.users.edit', $user));

    expect(Hash::check('sandi-baru-kuat-1', $user->refresh()->password))->toBeTrue();
});

it('refuses a password reset for staff of another branch', function () {
    $actor = User::factory()->adminCabang()->create();
    $stranger = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->put(route('admin.users.password.update', $stranger), [
            'password' => 'sandi-baru-kuat-1',
            'password_confirmation' => 'sandi-baru-kuat-1',
        ])
        ->assertForbidden();
});

it('soft deletes a staff account', function () {
    $actor = User::factory()->adminPusat()->create();
    $user = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $user))
        ->assertRedirect(route('admin.users.index'));

    $this->assertSoftDeleted($user);
});

it('refuses to let anyone delete their own account', function () {
    $actor = User::factory()->superAdmin()->create();

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $actor))
        ->assertForbidden();

    expect(User::whereKey($actor->id)->exists())->toBeTrue();
});

it('forbids a branch admin from deleting staff, even their own', function () {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();
    $user = User::factory()->kasir($branch)->create();

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $user))
        ->assertForbidden();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

/*
 * Review findings of 2026-11-04 (docs/bugs/2026-11-04-review-p1-e1-e2.md).
 */

it('still edits staff of a branch that is no longer active', function (BranchStatus $status) {
    $actor = User::factory()->adminPusat()->create();
    $branch = Branch::factory()->create(['status' => $status]);
    $user = User::factory()->kasir($branch)->create(['name' => 'Nama Lama', 'is_active' => true]);

    // BUG-02: the edit form resubmits the account's own branch, so limiting
    // `branch_id` to active branches used to refuse every other field with it.
    $this->actingAs($actor)
        ->put(route('admin.users.update', $user), [
            'name' => 'Nama Baru',
            'email' => $user->email,
            'phone' => '081299998888',
            'role' => UserRole::Kasir->value,
            'branch_id' => $branch->id,
            'is_active' => '0',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.edit', $user));

    expect($user->refresh())
        ->name->toBe('Nama Baru')
        ->is_active->toBeFalse()
        ->branch_id->toBe($branch->id);
})->with([
    'inactive branch' => [BranchStatus::Inactive],
    'branch under maintenance' => [BranchStatus::Maintenance],
]);

it('offers the branch an account already sits in even when it is closed', function () {
    $actor = User::factory()->superAdmin()->create();
    $branch = Branch::factory()->create(['name' => 'Cabang Tutup', 'status' => BranchStatus::Inactive]);
    $user = User::factory()->kasir($branch)->create();

    // BUG-02: the dropdown came up empty, so the screen could not even show
    // where the account was stationed.
    $this->actingAs($actor)
        ->get(route('admin.users.edit', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('options.branches', 1)
            ->where('options.branches.0.value', $branch->id)
            ->where('options.branches.0.label', 'Cabang Tutup ('.$branch->code.') — Tidak Aktif'));
});

it('refuses to move an account into a branch that is not active', function () {
    $actor = User::factory()->adminPusat()->create();
    $closed = Branch::factory()->create(['status' => BranchStatus::Inactive]);
    $user = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::Kasir->value,
            'branch_id' => $closed->id,
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('branch_id');

    expect($user->refresh()->branch_id)->not->toBe($closed->id);
});

it('refuses a branch role that has no branch instead of listing central staff', function () {
    // BUG-07: `where('branch_id', null)` compiles to `branch_id is null`, which
    // would have turned "only my branch" into "only central accounts". The
    // state is prevented by the forms, so it is refused at the door as well.
    $actor = User::factory()->adminCabang()->create();
    $actor->forceFill(['branch_id' => null])->save();

    User::factory()->adminPusat()->create();

    $this->actingAs($actor)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($actor)->get(route('admin.branches.index'))->assertForbidden();
});
