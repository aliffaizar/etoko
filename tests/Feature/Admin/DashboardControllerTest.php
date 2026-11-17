<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
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

it('counts this month sales by the report rule and the paid orders still waiting', function () {
    $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
    $branch = Branch::factory()->create();

    reportSale($branch, '2026-11-03 10:00:00', [[40_000, 2, 5_000]]);
    reportSale($branch, '2026-10-20 10:00:00', [[10_000, 1]]);
    Order::factory()->forBranch($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.sales', 75_000)
            ->where('stats.salesAllTime', 85_000)
            ->where('stats.transactions', 1)
            ->where('stats.transactionsAllTime', 2)
            ->where('stats.incomingOrders', 2)
            ->has('incomingOrders', 2)
            ->missing('salesChart')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('salesChart', 30)
                ->where('salesChart.29.key', '2026-11-17')
                ->where('branchPerformance.0.branch_id', $branch->id)
                ->where('branchPerformance.0.net', 75_000)));
});
