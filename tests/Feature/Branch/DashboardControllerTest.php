<?php

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;

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
