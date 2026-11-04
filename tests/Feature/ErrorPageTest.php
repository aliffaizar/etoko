<?php

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;

/*
 * BUG-04 (docs/bugs/2026-11-04-review-p1-e1-e2.md): refusals used to leave the
 * Inertia app entirely — Laravel's own English error page on a direct visit,
 * and a modal with that page in an iframe for an action fired from the panel,
 * because the response was not an Inertia response at all.
 */

it('renders a 403 as an Inertia page with a way back to the user own panel', function () {
    $actor = User::factory()->adminCabang()->create();

    $this->actingAs($actor)
        ->get(route('admin.roles.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('status', 403)
            ->where('title', 'Akses ditolak')
            ->where('homeUrl', route('branch.dashboard'))
            // Shared data is only there because the exception is rendered
            // through the Inertia middleware (`withSharedData`).
            ->where('auth.role', 'admin_cabang'));
});

it('shows the message the server attached to a refusal', function () {
    $branch = Branch::factory()->create();
    User::factory()->kasir($branch)->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('message', 'Cabang ini masih memiliki 1 akun staf. Pindahkan atau hapus akun tersebut terlebih dahulu.'));
});

it('leaves out the framework own English message', function () {
    $actor = User::factory()->adminCabang()->create();
    $stranger = User::factory()->kasir()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.edit', $stranger))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->where('message', null));
});

it('keeps the framework own 404 and 500 messages off the screen', function () {
    // "The route x could not be found." and "No query results for model
    // [App\Models\Warehouse]" are not for the user to read.
    $this->actingAs(User::factory()->adminPusat()->create())
        ->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->where('message', null));

    $branch = Branch::factory()->create();
    $foreign = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.warehouses.update', [
            $branch,
            Warehouse::factory()->for($foreign)->create(),
        ]), [])
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->where('message', null));
});

it('renders a 404 as an Inertia page', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('error')
            ->where('status', 404)
            ->where('title', 'Halaman tidak ditemukan')
            ->where('homeUrl', route('home')));
});

it('sends a guest back to the public home page', function () {
    $this->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->where('homeUrl', route('home'))
            ->where('homeLabel', 'Kembali ke halaman utama'));
});
