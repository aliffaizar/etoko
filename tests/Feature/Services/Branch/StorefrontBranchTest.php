<?php

use App\Models\Branch;
use App\Services\Branch\StorefrontBranch;

/*
 * The branch a shopper shops from (P1-E5-T2, ADR-028).
 *
 * The rule under every test here: this is the shopper's own choice, kept in
 * their session, and it is never the signed-in user's `branch_id`.
 */

function storefront(): StorefrontBranch
{
    return app(StorefrontBranch::class);
}

it('falls back to the main branch when nothing has been chosen', function () {
    Branch::factory()->create();
    $main = Branch::factory()->default()->create();

    expect(storefront()->current()->getKey())->toBe($main->getKey())
        ->and(storefront()->hasChosen())->toBeFalse();
});

it('remembers the branch the shopper picked', function () {
    Branch::factory()->default()->create();
    $picked = Branch::factory()->create();

    storefront()->choose($picked);

    expect(session(StorefrontBranch::SESSION_KEY))->toBe($picked->getKey())
        ->and(storefront()->current()->getKey())->toBe($picked->getKey())
        ->and(storefront()->hasChosen())->toBeTrue();
});

it('ignores a chosen branch that no longer accepts orders', function () {
    $main = Branch::factory()->default()->create();
    $closed = Branch::factory()->inactive()->create();

    session([StorefrontBranch::SESSION_KEY => $closed->getKey()]);

    // Treated as no choice at all rather than as a dead end: a session from
    // last week must not leave somebody looking at a shop that cannot sell.
    expect(storefront()->current()->getKey())->toBe($main->getKey())
        ->and(storefront()->hasChosen())->toBeFalse();
});

it('offers only branches that accept orders', function () {
    $open = Branch::factory()->default()->create();
    Branch::factory()->inactive()->create();
    Branch::factory()->underMaintenance()->create();

    expect(storefront()->sellingBranches()->modelKeys())->toBe([$open->getKey()]);
});

it('has no branch at all before any has been created', function () {
    expect(storefront()->current())->toBeNull();
});

it('picks the closest branch to a point', function () {
    // Monas, Jakarta; Bandung is ~120 km away, Surabaya ~660 km.
    $jakarta = Branch::factory()->default()->at(-6.1754, 106.8272)->create();
    $bandung = Branch::factory()->at(-6.9175, 107.6191)->create();
    Branch::factory()->at(-7.2575, 112.7521)->create();

    expect(storefront()->nearestTo(-6.2000, 106.8167)->getKey())->toBe($jakarta->getKey())
        ->and(storefront()->nearestTo(-6.9000, 107.6000)->getKey())->toBe($bandung->getKey());
});

it('skips branches that have no coordinates when ranking by distance', function () {
    Branch::factory()->default()->withoutCoordinates()->create();
    $located = Branch::factory()->at(-7.2575, 112.7521)->create();

    expect(storefront()->nearestTo(-6.2000, 106.8167)->getKey())->toBe($located->getKey());
});

it('ranks no branch when none has coordinates', function () {
    Branch::factory()->default()->withoutCoordinates()->create();

    expect(storefront()->nearestTo(-6.2000, 106.8167))->toBeNull();
});

it('never ranks a closed branch as the nearest', function () {
    $open = Branch::factory()->default()->at(-7.2575, 112.7521)->create();
    Branch::factory()->inactive()->at(-6.2000, 106.8167)->create();

    expect(storefront()->nearestTo(-6.2000, 106.8167)->getKey())->toBe($open->getKey());
});
