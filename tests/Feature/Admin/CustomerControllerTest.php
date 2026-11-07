<?php

use App\Models\Address;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;

/*
 * Admin customer listing and profile (P1-E6-T7).
 *
 * Customers are `role = customer` accounts. The screen is read-only
 * (customers.view only) and is separate from the staff user screen.
 */

// ── Index ──────────────────────────────────────────────────────────────────────

describe('index', function () {
    it('redirects guests to the login page', function () {
        $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
    });

    it('renders the customer listing for super admin', function () {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->customer()->count(3)->create();

        $this->actingAs($actor)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/customers/index')
                ->has('customers.data', 3));
    });

    it('renders the customer listing for admin pusat', function () {
        $actor = User::factory()->adminPusat()->create();

        $this->actingAs($actor)
            ->get(route('admin.customers.index'))
            ->assertOk();
    });

    it('forbids a customer from accessing the admin panel', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('admin.customers.index'))
            ->assertForbidden();
    });

    it('filters by name or email search', function () {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->customer()->create(['name' => 'Budi Santoso', 'email' => 'budi@test.com']);
        User::factory()->customer()->create(['name' => 'Ani Rahayu', 'email' => 'ani@test.com']);

        $this->actingAs($actor)
            ->get(route('admin.customers.index', ['search' => 'Budi']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers.data', 1));
    });

    it('filters by active status', function () {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->customer()->create(['is_active' => true]);
        User::factory()->customer()->create(['is_active' => false]);

        $this->actingAs($actor)
            ->get(route('admin.customers.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers.data', 1));
    });

    it('does not list staff users', function () {
        $actor = User::factory()->superAdmin()->create();
        User::factory()->customer()->create();
        // $actor counts as super admin, not customer — only 1 customer should appear
        $branch = Branch::factory()->create();
        User::factory()->staffGudang($branch)->create();

        $this->actingAs($actor)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers.data', 1));
    });
});

// ── Show ───────────────────────────────────────────────────────────────────────

describe('show', function () {
    it('renders the customer profile page', function () {
        $actor = User::factory()->superAdmin()->create();
        $customer = User::factory()->customer()->create();
        Address::factory()->forUser($customer)->count(2)->create();

        $this->actingAs($actor)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/customers/show')
                ->where('customer.id', $customer->getKey())
                ->has('customer.addresses', 2));
    });

    it('returns 404 when the user is not a customer', function () {
        $actor = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $staff = User::factory()->staffGudang($branch)->create();

        $this->actingAs($actor)
            ->get(route('admin.customers.show', $staff))
            ->assertNotFound();
    });

    it('forbids a customer from viewing another customer profile', function () {
        $viewer = User::factory()->customer()->create();
        $target = User::factory()->customer()->create();

        $this->actingAs($viewer)
            ->get(route('admin.customers.show', $target))
            ->assertForbidden();
    });

    /*
     * The order figures, added in P1-E7-T8.
     *
     * Only settled money counts as "spent": an unpaid order is a hope, and
     * counting it would overstate every customer.
     */
    it('counts the orders and totals only the money that actually arrived', function () {
        $actor = User::factory()->superAdmin()->create();
        $customer = User::factory()->customer()->create();
        $branch = Branch::factory()->create();

        Order::factory()->forBranch($branch)->forCustomer($customer)
            ->paid()->create(['grand_total' => 250_000]);
        Order::factory()->forBranch($branch)->forCustomer($customer)
            ->create(['grand_total' => 900_000]);

        $this->actingAs($actor)
            ->get(route('admin.customers.show', $customer))
            ->assertInertia(fn ($page) => $page
                ->where('customer.order_count', 2)
                ->where('customer.total_spend', 250_000)
                ->has('orders', 2));
    });

    it('shows a branch admin only the orders their own branch filled (BR-3)', function () {
        $mine = Branch::factory()->create();
        $theirs = Branch::factory()->create();
        $actor = User::factory()->adminCabang($mine)->create();
        $actor->givePermissionTo('customers.view');
        $customer = User::factory()->customer()->create();

        Order::factory()->forBranch($mine)->forCustomer($customer)
            ->paid()->create(['grand_total' => 100_000]);
        Order::factory()->forBranch($theirs)->forCustomer($customer)
            ->paid()->create(['grand_total' => 500_000]);

        $this->actingAs($actor)
            ->get(route('admin.customers.show', $customer))
            ->assertInertia(fn ($page) => $page
                ->where('customer.order_count', 1)
                ->where('customer.total_spend', 100_000)
                ->has('orders', 1));
    });
});
