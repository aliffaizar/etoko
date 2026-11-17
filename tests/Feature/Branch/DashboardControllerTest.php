<?php

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

it('redirects guests to the login page', function () {
    $this->get(route('branch.dashboard'))->assertRedirect(route('login'));
});

it('renders the branch dashboard for a branch admin', function () {
    $user = User::factory()->adminCabang()->create();

    $this->actingAs($user)
        ->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/dashboard')->has('stats'));
});

it('counts only warehouses of the acting user branch', function () {
    $branch = Branch::factory()->create();
    Warehouse::factory()->count(2)->for($branch)->create();
    Warehouse::factory()->count(3)->create();

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.warehouses', 2));
});

it('forbids a branch user who has no branch assigned', function () {
    $user = User::factory()->adminCabang()->create(['branch_id' => null]);

    $this->actingAs($user)->get(route('branch.dashboard'))->assertForbidden();
});

it('forbids customers', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('branch.dashboard'))
        ->assertForbidden();
});

it('allows central staff to supervise the branch panel', function () {
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('branch.dashboard'))
        ->assertOk();
});

it('shows a branch admin the sales and order queue of their own branch only', function () {
    $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();

    reportSale($mine, '2026-11-03 10:00:00', [[30_000, 1]]);
    reportSale($theirs, '2026-11-03 10:00:00', [[90_000, 1]]);
    Order::factory()->forBranch($mine)->processing()->create();
    User::factory()->staffGudang($theirs)->create();

    $this->actingAs(User::factory()->adminCabang($mine)->create())
        ->get(route('branch.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('sales.net', 30_000)
            ->where('sales.orders', 1)
            ->where('orders.incoming', 1)
            ->where('orders.processing', 1)
            ->where('stats.staff', 1)
            ->where('branchFilter.options', [])
            ->loadDeferredProps(fn ($reload) => $reload->where('salesChart.29.net', 0)));
});

it('hides sales from staff who may not read them', function () {
    $this->actingAs(User::factory()->staffGudang(Branch::factory()->create())->create())
        ->get(route('branch.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('sales', null)
            ->where('salesChart', null)
            ->has('stock'));
});

it('counts what came in and went out this month from the ledger', function () {
    $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::factory()->for($branch)->create();
    $variant = ProductVariant::factory()->create();

    DB::transaction(function () use ($warehouse, $variant): void {
        $ledger = app(StockLedger::class);
        $ledger->apply($warehouse, $variant, StockMovementType::EntryIn, 12);
        $ledger->apply($warehouse, $variant, StockMovementType::SaleOut, -5);
    });

    $this->actingAs(User::factory()->adminCabang($branch)->create())
        ->get(route('branch.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stock.units', 7)
            ->where('stock.in_month', 12)
            ->where('stock.out_month', 5));
});

it('lets central staff look at one branch with ?branch=', function () {
    $jakarta = Branch::factory()->create();
    $bandung = Branch::factory()->create();
    reportSale($jakarta, now()->toDateTimeString(), [[10_000, 1]]);
    reportSale($bandung, now()->toDateTimeString(), [[90_000, 1]]);

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('branch.dashboard', ['branch' => $bandung->id]))
        ->assertInertia(fn ($page) => $page
            ->where('sales.net', 90_000)
            ->where('branchFilter.selected', (string) $bandung->id)
            ->loadDeferredProps(fn ($reload) => $reload->where('salesChart.29.net', 90_000)));
});
