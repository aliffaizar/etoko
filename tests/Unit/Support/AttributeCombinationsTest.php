<?php

use App\Support\AttributeCombinations;

/*
 * Expanding product options into variant combinations (P1-E3-T6).
 *
 * Pure arithmetic over ids, so these are unit tests with no database.
 */

it('expands two attributes into every pairing, in order', function () {
    expect(AttributeCombinations::cartesian([[1, 2], [10, 11]]))->toBe([
        [1, 10],
        [1, 11],
        [2, 10],
        [2, 11],
    ]);
});

it('returns one combination per value for a single attribute', function () {
    expect(AttributeCombinations::cartesian([[1, 2, 3]]))->toBe([[1], [2], [3]]);
});

it('yields nothing when no option was chosen', function () {
    expect(AttributeCombinations::cartesian([]))->toBe([])
        ->and(AttributeCombinations::cartesian([[], []]))->toBe([]);
});

it('skips an attribute that was left without values', function () {
    expect(AttributeCombinations::cartesian([[1, 2], [], [10]]))->toBe([[1, 10], [2, 10]]);
});

it('counts a value only once when it was given twice', function () {
    expect(AttributeCombinations::cartesian([[1, 1, 2]]))->toBe([[1], [2]]);
});

it('counts the combinations without building them', function () {
    expect(AttributeCombinations::count([[1, 2], [10, 11, 12]]))->toBe(6)
        ->and(AttributeCombinations::count([[1, 1, 2]]))->toBe(2)
        ->and(AttributeCombinations::count([[1, 2], []]))->toBe(2)
        ->and(AttributeCombinations::count([]))->toBe(0)
        ->and(AttributeCombinations::count([[], []]))->toBe(0);
});

it('agrees with cartesian on how many combinations there are', function (array $groups) {
    expect(AttributeCombinations::count($groups))->toBe(count(AttributeCombinations::cartesian($groups)));
})->with([
    'one axis' => [[[1, 2, 3]]],
    'two axes' => [[[1, 2], [10, 11]]],
    'three axes' => [[[1, 2], [10, 11], [20, 21, 22]]],
    'an empty axis among them' => [[[1, 2], [], [20]]],
    'nothing at all' => [[]],
]);

it('fingerprints a combination the same however it was ordered', function () {
    expect(AttributeCombinations::fingerprint([11, 2]))
        ->toBe(AttributeCombinations::fingerprint([2, 11]));
});

it('tells different combinations apart', function () {
    expect(AttributeCombinations::fingerprint([1, 2]))
        ->not->toBe(AttributeCombinations::fingerprint([1, 3]));
});

it('fingerprints a combination without options as an empty key', function () {
    expect(AttributeCombinations::fingerprint([]))->toBe('');
});

it('does not confuse ids that share digits', function () {
    // Naive concatenation would make [1, 12] and [11, 2] the same key.
    expect(AttributeCombinations::fingerprint([1, 12]))
        ->not->toBe(AttributeCombinations::fingerprint([11, 2]));
});
