<?php

use App\Models\Branch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login page', function () {
    $this->get(route('admin.reports.sales'))->assertRedirect(route('login'));
});

it('opens each report for the roles that hold its permission', function (string $route, string $component) {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->missing('report')
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('report')));
})->with([
    'sales' => ['admin.reports.sales', 'admin/reports/sales'],
    'transactions' => ['admin.reports.transactions', 'admin/reports/transactions'],
    'stock' => ['admin.reports.stock', 'admin/reports/stock'],
    'stock card' => ['admin.reports.stock-card', 'admin/reports/stock-card'],
    'products' => ['admin.reports.products', 'admin/reports/products'],
    'customers' => ['admin.reports.customers', 'admin/reports/customers'],
    'branches' => ['admin.reports.branches', 'admin/reports/branches'],
]);

it('lets warehouse staff open the stock reports and nothing else', function (string $route, int $status) {
    $this->actingAs(User::factory()->staffGudang(Branch::factory()->create())->create())
        ->get(route($route))
        ->assertStatus($status);
})->with([
    'stock' => ['admin.reports.stock', 200],
    'stock card' => ['admin.reports.stock-card', 200],
    'sales' => ['admin.reports.sales', 403],
    'customers' => ['admin.reports.customers', 403],
    'branches' => ['admin.reports.branches', 403],
]);

it('refuses cashiers and customers', function (string $state) {
    $user = $state === 'kasir'
        ? User::factory()->kasir(Branch::factory()->create())->create()
        : User::factory()->customer()->create();

    $this->actingAs($user)->get(route('admin.reports.sales'))->assertForbidden();
})->with(['kasir', 'customer']);

it('shows a branch admin only their own branch, whatever branch they name', function () {
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();
    reportSale($mine, now()->subDay()->toDateTimeString(), [[10_000, 1]]);
    reportSale($theirs, now()->subDay()->toDateTimeString(), [[90_000, 1]]);

    $this->actingAs(User::factory()->adminCabang($mine)->create())
        ->get(route('admin.reports.sales', ['branch' => $theirs->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.branch', '')
            ->where('options.branches', [])
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('report.summary.net', 10_000)
                ->where('report.summary.orders', 1)));
});

it('lets central staff narrow a report to one branch', function () {
    $jakarta = Branch::factory()->create();
    $bandung = Branch::factory()->create();
    reportSale($jakarta, now()->subDay()->toDateTimeString(), [[10_000, 1]]);
    reportSale($bandung, now()->subDay()->toDateTimeString(), [[90_000, 1]]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.branches', ['branch' => $bandung->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('report.rows', 1)
                ->where('report.rows.0.branch_id', $bandung->id)
                ->where('report.rows.0.net', 90_000)));
});

it('opens on the last thirty days when no period is named', function () {
    $this->freezeTime();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.sales'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->subDays(29)->toDateString())
            ->where('filters.until', now()->toDateString()));
});

it('rejects a period that ends before it starts or runs past two years', function (array $query, string $message) {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.sales', $query))
        ->assertSessionHasErrors(['until' => $message]);
})->with([
    'backwards' => [['from' => '2026-11-10', 'until' => '2026-11-01'], 'tanggal akhir harus tanggal setelah atau sama dengan tanggal awal.'],
    'too long' => [['from' => '2024-01-01', 'until' => '2026-11-01'], 'Periode laporan paling panjang dua tahun.'],
]);

it('refuses a sort column no report knows', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.products', ['sort' => 'id; drop table orders']))
        ->assertSessionHasErrors('sort');
});
