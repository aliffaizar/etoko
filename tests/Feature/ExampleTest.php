<?php

use App\Models\Branch;

/*
 * The storefront front page renders (P1-E5-T1).
 *
 * The branch is the new precondition: `/` is the shop now, and a shop with no
 * branch has no price and no stock to show, so it answers 503 instead of
 * inventing figures (ADR-028). The assertion is the one this test always
 * made — the home page comes back — and `HomeControllerTest` covers what is
 * on it.
 */
test('returns a successful response', function () {
    Branch::factory()->default()->create();

    $response = $this->get(route('home'));

    $response->assertOk();
});
