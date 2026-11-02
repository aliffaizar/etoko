<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('renders the central dashboard for central staff', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/dashboard')->has('stats'));
})->with([
    'super admin' => [UserRole::SuperAdmin],
    'admin pusat' => [UserRole::AdminPusat],
]);

it('forbids roles without the central dashboard permission', function (UserRole $role) {
    $user = User::factory()->withRole($role)->forBranch()->create();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
})->with([
    'admin cabang' => [UserRole::AdminCabang],
    'staff gudang' => [UserRole::StaffGudang],
    'kasir' => [UserRole::Kasir],
]);

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('counts every branch regardless of the acting user', function () {
    Branch::factory()->count(2)->create();
    Branch::factory()->inactive()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.branches', 3)
            ->where('stats.activeBranches', 2));
});
