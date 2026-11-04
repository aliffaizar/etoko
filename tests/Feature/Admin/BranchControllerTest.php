<?php

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;

/*
 * Branch management (P1-E2-T3).
 *
 * These tests run against the real permission baseline from config/rbac.php, so
 * a change there that widens access shows up here.
 */

function branchPayload(array $overrides = []): array
{
    return [
        'code' => 'JKT-05',
        'name' => 'Cabang Jakarta Selatan',
        'description' => 'Cabang baru di selatan.',
        'phone' => '0215550005',
        'email' => 'jaksel@etoko.test',
        'province_name' => 'DKI Jakarta',
        'city_name' => 'Jakarta Selatan',
        'district_name' => 'Cilandak',
        'postal_code' => '12430',
        'address_line' => 'Jl. TB Simatupang No. 5',
        'latitude' => '-6.2900000',
        'longitude' => '106.8000000',
        'status' => BranchStatus::Active->value,
        'supports_delivery' => true,
        'supports_pickup' => true,
        'sort_order' => 3,
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('admin.branches.index'))->assertRedirect(route('login'));
});

it('lists branches for central staff', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->create();
    Branch::factory()->count(2)->create();

    $this->actingAs($actor)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/branches/index')
            ->has('branches.data', 2)
            ->has('filters')
            ->where('can.create', true));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('shows a branch admin only their own branch', function () {
    $branch = Branch::factory()->create(['name' => 'Cabang Saya']);
    Branch::factory()->count(2)->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('branches.data', 1)
            ->where('branches.data.0.name', 'Cabang Saya')
            ->where('can.create', false));
});

it('forbids roles without the branches.view permission', function (UserRole $role) {
    $actor = User::factory()->withRole($role)->forBranch()->create();

    $this->actingAs($actor)->get(route('admin.branches.index'))->assertForbidden();
})->with([
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.branches.index'))
        ->assertForbidden();
});

it('counts warehouses and staff per branch', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->count(2)->for($branch)->create();
    User::factory()->count(3)->kasir($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.index'))
        ->assertInertia(fn ($page) => $page
            ->where('branches.data.0.warehouse_count', 2)
            ->where('branches.data.0.staff_count', 3));
});

it('filters the listing by status and fulfilment support', function () {
    $actor = User::factory()->adminPusat()->create();

    Branch::factory()->create(['name' => 'Aktif Kirim']);
    Branch::factory()->inactive()->create(['name' => 'Nonaktif']);
    Branch::factory()->pickupOnly()->create(['name' => 'Hanya Ambil']);

    $this->actingAs($actor)
        ->get(route('admin.branches.index', ['status' => BranchStatus::Inactive->value]))
        ->assertInertia(fn ($page) => $page
            ->has('branches.data', 1)
            ->where('branches.data.0.name', 'Nonaktif'));

    $this->actingAs($actor)
        ->get(route('admin.branches.index', ['fulfillment' => 'delivery']))
        ->assertInertia(fn ($page) => $page->has('branches.data', 2));
});

it('searches the listing by name, code and city', function () {
    $actor = User::factory()->adminPusat()->create();

    Branch::factory()->create(['code' => 'BDG-01', 'name' => 'Cabang Bandung', 'city_name' => 'Bandung']);
    Branch::factory()->create(['code' => 'SBY-01', 'name' => 'Cabang Surabaya', 'city_name' => 'Surabaya']);

    $this->actingAs($actor)
        ->get(route('admin.branches.index', ['search' => 'BDG']))
        ->assertInertia(fn ($page) => $page->has('branches.data', 1)->where('branches.data.0.code', 'BDG-01'));

    $this->actingAs($actor)
        ->get(route('admin.branches.index', ['search' => 'Surabaya']))
        ->assertInertia(fn ($page) => $page->has('branches.data', 1)->where('branches.data.0.code', 'SBY-01'));
});

it('rejects an unknown sort column', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.index', ['sort' => 'password']))
        ->assertSessionHasErrors('sort');
});

it('creates a branch with its default warehouse and a full week of hours', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.branches.store'), branchPayload())
        ->assertRedirect();

    $branch = Branch::where('code', 'JKT-05')->sole();

    expect($branch->slug)->toBe('cabang-jakarta-selatan')
        ->and($branch->warehouses()->count())->toBe(1)
        ->and($branch->operatingHours()->count())->toBe(7)
        // The first branch created becomes the storefront fallback.
        ->and($branch->is_default)->toBeTrue();
});

it('forbids a branch admin from creating a branch', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->post(route('admin.branches.store'), branchPayload())
        ->assertForbidden();

    expect(Branch::where('code', 'JKT-05')->exists())->toBeFalse();
});

it('requires a unique branch code', function () {
    Branch::factory()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload())
        ->assertSessionHasErrors('code');
});

it('uppercases the submitted branch code', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload(['code' => 'jkt-05']));

    expect(Branch::where('code', 'JKT-05')->exists())->toBeTrue();
});

it('rejects a branch code with illegal characters', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload(['code' => 'JKT 05!']))
        ->assertSessionHasErrors('code');
});

it('refuses a branch that supports neither delivery nor pickup', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload([
            'supports_delivery' => false,
            'supports_pickup' => false,
        ]))
        ->assertSessionHasErrors('supports_delivery');

    expect(Branch::count())->toBe(0);
});

it('requires an address and a city', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload([
            'address_line' => '',
            'city_name' => '',
            'province_name' => '',
        ]))
        ->assertSessionHasErrors(['address_line', 'city_name', 'province_name']);
});

it('rejects coordinates outside the possible range', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->post(route('admin.branches.store'), branchPayload(['latitude' => '-120', 'longitude' => '500']))
        ->assertSessionHasErrors(['latitude', 'longitude']);
});

it('treats an emptied sort order as no preference', function () {
    $actor = User::factory()->adminPusat()->create();

    $this->actingAs($actor)
        ->post(route('admin.branches.store'), branchPayload(['sort_order' => '']))
        ->assertRedirect();

    expect(Branch::where('code', 'JKT-05')->value('sort_order'))->toBe(0);

    $branch = Branch::where('code', 'JKT-05')->sole();

    $this->actingAs($actor)
        ->put(route('admin.branches.update', $branch), branchPayload([
            'slug' => $branch->slug,
            'is_default' => true,
            'sort_order' => '',
        ]))
        ->assertRedirect();

    expect($branch->refresh()->sort_order)->toBe(0);
});

it('shows the branch screen with hours, warehouses and staff', function () {
    $branch = Branch::factory()->default()->create();
    Warehouse::factory()->default()->for($branch)->create();
    User::factory()->kasir($branch)->create(['name' => 'Kasir Cabang']);
    User::factory()->kasir()->create(['name' => 'Kasir Cabang Lain']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/branches/edit')
            ->where('branch.id', $branch->id)
            ->has('operatingHours', 7)
            ->has('warehouses', 1)
            ->has('staff', 1)
            ->where('staff.0.name', 'Kasir Cabang')
            ->where('can.update', true)
            ->where('can.manage_warehouses', true)
            // The storefront fallback branch cannot be deleted.
            ->where('can.delete', false));
});

it('offers the staff of other branches for assignment', function () {
    $branch = Branch::factory()->create();
    User::factory()->kasir()->create(['name' => 'Kasir Pindahan']);
    User::factory()->adminPusat()->create(['name' => 'Admin Pusat Lain']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertInertia(fn ($page) => $page
            ->has('assignableStaff', 1)
            ->where('assignableStaff.0.label', fn (string $label) => str_contains($label, 'Kasir Pindahan')));
});

it('lets a branch admin open their own branch but not another', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)->get(route('admin.branches.edit', $branch))->assertOk();
    $this->actingAs($actor)->get(route('admin.branches.edit', $other))->assertForbidden();
});

it('hides branch deletion and staff assignment from a branch admin', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('can.delete', false)
            ->where('can.assign_staff', false)
            ->has('assignableStaff', 0));
});

it('fills in missing days as closed so the week is always complete', function () {
    $branch = Branch::factory()->create();
    $branch->operatingHours()->create(['day_of_week' => 1, 'opens_at' => '09:00:00', 'closes_at' => '18:00:00']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertInertia(fn ($page) => $page
            ->has('operatingHours', 7)
            ->where('operatingHours.1.opens_at', '09:00')
            ->where('operatingHours.1.is_closed', false)
            ->where('operatingHours.3.is_closed', true));
});

it('updates a branch', function () {
    // Not the default branch: that one may not leave the Active status at all
    // (ADR-017), which the two tests below cover.
    $branch = Branch::factory()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.update', $branch), branchPayload([
            'slug' => 'cabang-jakarta-selatan',
            'name' => 'Cabang Jaksel',
            'status' => BranchStatus::Maintenance->value,
        ]))
        ->assertRedirect(route('admin.branches.edit', $branch));

    expect($branch->refresh())
        ->name->toBe('Cabang Jaksel')
        ->slug->toBe('cabang-jakarta-selatan')
        ->status->toBe(BranchStatus::Maintenance);
});

it('lets a branch admin update their own branch only', function () {
    $branch = Branch::factory()->create(['code' => 'JKT-05']);
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->put(route('admin.branches.update', $branch), branchPayload([
            'slug' => $branch->slug,
            'name' => 'Nama Dari Cabang',
        ]))
        ->assertRedirect();

    expect($branch->refresh()->name)->toBe('Nama Dari Cabang');

    $this->actingAs($actor)
        ->put(route('admin.branches.update', $other), branchPayload([
            'code' => 'XXX-01',
            'slug' => $other->slug,
        ]))
        ->assertForbidden();
});

it('refuses to drop the storefront default flag without a replacement', function () {
    $branch = Branch::factory()->default()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.update', $branch), branchPayload([
            'slug' => $branch->slug,
            'is_default' => false,
        ]))
        ->assertSessionHasErrors('is_default');

    expect($branch->refresh()->is_default)->toBeTrue();
});

it('refuses to deactivate the default branch', function () {
    $branch = Branch::factory()->default()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.update', $branch), branchPayload([
            'slug' => $branch->slug,
            'is_default' => true,
            'status' => BranchStatus::Inactive->value,
        ]))
        ->assertSessionHasErrors('status');

    expect($branch->refresh()->status)->toBe(BranchStatus::Active);
});

it('requires a unique slug', function () {
    Branch::factory()->create(['slug' => 'cabang-lain']);
    $branch = Branch::factory()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.update', $branch), branchPayload(['slug' => 'cabang-lain']))
        ->assertSessionHasErrors('slug');
});

it('changes a branch status from the listing', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.branches.status.update', $branch), ['status' => BranchStatus::Maintenance->value])
        ->assertRedirect();

    expect($branch->refresh()->status)->toBe(BranchStatus::Maintenance);
});

it('refuses to switch off the default branch from the listing', function () {
    $branch = Branch::factory()->default()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.branches.status.update', $branch), ['status' => BranchStatus::Inactive->value])
        ->assertSessionHasErrors('status');

    expect($branch->refresh()->status)->toBe(BranchStatus::Active);
});

it('soft deletes a branch', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect(route('admin.branches.index'));

    expect($branch->refresh()->trashed())->toBeTrue()
        ->and(Branch::count())->toBe(0);
});

it('refuses to delete the storefront default branch', function () {
    $branch = Branch::factory()->default()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertForbidden();

    expect($branch->refresh()->trashed())->toBeFalse();
});

it('refuses to delete a branch that still has staff', function () {
    $branch = Branch::factory()->create();
    User::factory()->kasir($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertForbidden();

    expect($branch->refresh()->trashed())->toBeFalse();
});

it('forbids a branch admin from deleting their own branch', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertForbidden();

    expect($branch->refresh()->trashed())->toBeFalse();
});

it('records branch changes in the audit log', function () {
    $branch = Branch::factory()->create(['code' => 'JKT-05']);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->patch(route('admin.branches.status.update', $branch), ['status' => BranchStatus::Maintenance->value]);

    expect($branch->auditLogs()->where('event', 'updated')->exists())->toBeTrue();
});

/*
 * Review findings of 2026-11-04 (docs/bugs/2026-11-04-review-p1-e1-e2.md).
 */

it('keeps the storefront fallback branch open for business', function (BranchStatus $status) {
    // BUG-03: both guards compared against `Inactive` only, but maintenance
    // does not accept orders either (BranchStatus::acceptsOrders).
    $branch = Branch::factory()->default()->create(['status' => BranchStatus::Active]);
    $actor = User::factory()->superAdmin()->create();

    $this->actingAs($actor)
        ->patch(route('admin.branches.status.update', $branch), ['status' => $status->value])
        ->assertSessionHasErrors('status');

    $this->actingAs($actor)
        ->put(route('admin.branches.update', $branch), branchPayload([
            'code' => $branch->code,
            'slug' => $branch->slug,
            'is_default' => true,
            'status' => $status->value,
        ]))
        ->assertSessionHasErrors('status');

    expect($branch->refresh()->status)->toBe(BranchStatus::Active);
})->with([
    'inactive' => [BranchStatus::Inactive],
    'maintenance' => [BranchStatus::Maintenance],
]);

it('lets a branch that is not the fallback go into maintenance', function () {
    $branch = Branch::factory()->create(['status' => BranchStatus::Active]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->patch(route('admin.branches.status.update', $branch), ['status' => BranchStatus::Maintenance->value])
        ->assertSessionHasNoErrors();

    expect($branch->refresh()->status)->toBe(BranchStatus::Maintenance);
});

it('does not offer deletion of a branch the server would refuse', function () {
    // BUG-05: `can.delete` only weighed `is_default`, so the listing offered
    // "Hapus" for every branch that still had staff and the attempt died in a
    // raw error modal.
    $withStaff = Branch::factory()->create(['name' => 'Cabang Berstaf', 'sort_order' => 1]);
    User::factory()->kasir($withStaff)->create();
    Branch::factory()->create(['name' => 'Cabang Kosong', 'sort_order' => 2]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.index'))
        ->assertInertia(fn ($page) => $page
            ->where('branches.data.0.can.delete', false)
            ->where('branches.data.0.delete_blocked_reason', 'Cabang ini masih memiliki 1 akun staf. Pindahkan atau hapus akun tersebut terlebih dahulu.')
            ->where('branches.data.1.can.delete', true)
            ->where('branches.data.1.delete_blocked_reason', null));
});

it('explains on the branch screen why deletion is refused', function () {
    $branch = Branch::factory()->default()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.branches.edit', $branch))
        ->assertInertia(fn ($page) => $page
            ->where('can.delete', false)
            ->where('can.delete_blocked_reason', 'Cabang utama tidak dapat dihapus. Tunjuk cabang lain sebagai cabang utama terlebih dahulu.'));
});

it('leaves the blocked reason out for an actor who may not delete branches', function () {
    $branch = Branch::factory()->create();
    User::factory()->kasir($branch)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('admin.branches.index'))
        ->assertInertia(fn ($page) => $page
            ->where('branches.data.0.can.delete', false)
            ->where('branches.data.0.delete_blocked_reason', null));
});
