<?php

use App\Models\Branch;
use App\Models\User;
use App\Services\Branch\StorefrontBranch;

/*
 * Picking where to shop (P1-E5-T2).
 *
 * The choice is the shopper's own and lives in their session, so it is open to
 * guests and it never touches a staff account's `branch_id` (ADR-028).
 */

beforeEach(function () {
    $this->main = Branch::factory()->default()->at(-6.1754, 106.8272)->create();
});

it('lets a guest choose a branch', function () {
    $other = Branch::factory()->create();

    $this->from(route('home'))
        ->put(route('shop.branch.update'), ['branch_id' => $other->getKey()])
        ->assertRedirect(route('home'));

    expect(session(StorefrontBranch::SESSION_KEY))->toBe($other->getKey());
});

it('sends the shopper back to the page they were on', function () {
    $product = Branch::factory()->create();

    // Switching branch changes every price on the page the shopper is reading,
    // so the point is to re-render that page rather than send them home.
    $this->from(route('shop.catalog.index'))
        ->put(route('shop.branch.update'), ['branch_id' => $product->getKey()])
        ->assertRedirect(route('shop.catalog.index'));
});

it('refuses a branch that is not accepting orders', function () {
    $closed = Branch::factory()->inactive()->create();

    $this->put(route('shop.branch.update'), ['branch_id' => $closed->getKey()])
        ->assertSessionHasErrors('branch_id');

    expect(session(StorefrontBranch::SESSION_KEY))->toBeNull();
});

it('refuses a branch that does not exist', function () {
    $this->put(route('shop.branch.update'), ['branch_id' => 9_999])
        ->assertSessionHasErrors('branch_id');
});

it('demands a branch', function () {
    $this->put(route('shop.branch.update'), [])->assertSessionHasErrors('branch_id');
});

it('picks the nearest branch from a position', function () {
    $surabaya = Branch::factory()->at(-7.2575, 112.7521)->create();

    $this->from(route('home'))
        ->post(route('shop.branch.nearest'), ['latitude' => -7.2600, 'longitude' => 112.7500])
        ->assertRedirect(route('home'));

    expect(session(StorefrontBranch::SESSION_KEY))->toBe($surabaya->getKey());
});

it('leaves the choice alone when no branch has a position', function () {
    Branch::query()->forceDelete();
    Branch::factory()->default()->withoutCoordinates()->create();

    $this->from(route('home'))
        ->post(route('shop.branch.nearest'), ['latitude' => -6.2, 'longitude' => 106.8])
        ->assertRedirect(route('home'));

    // Quietly assigning the main branch would look like the location had been
    // used, so nothing is remembered and the shopper is told to pick.
    expect(session(StorefrontBranch::SESSION_KEY))->toBeNull();
});

it('refuses coordinates that are not on the globe', function () {
    $this->post(route('shop.branch.nearest'), ['latitude' => 120, 'longitude' => 500])
        ->assertSessionHasErrors(['latitude', 'longitude']);
});

it('demands both coordinates', function () {
    $this->post(route('shop.branch.nearest'), ['latitude' => -6.2])
        ->assertSessionHasErrors('longitude');
});

it('does not move a staff account own branch when they shop elsewhere', function () {
    $staffBranch = Branch::factory()->create();
    $staff = User::factory()->staffGudang($staffBranch)->create();
    $shopFrom = Branch::factory()->create();

    $this->actingAs($staff)
        ->from(route('home'))
        ->put(route('shop.branch.update'), ['branch_id' => $shopFrom->getKey()]);

    expect($staff->fresh()->branch_id)->toBe($staffBranch->getKey())
        ->and(session(StorefrontBranch::SESSION_KEY))->toBe($shopFrom->getKey());
});
