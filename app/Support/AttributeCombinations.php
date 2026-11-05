<?php

namespace App\Support;

/**
 * Expands the options a product varies by into one list per variant
 * (P1-E3-T6).
 *
 * Given Warna = [Merah, Biru] and Ukuran = [M, L], this yields the four
 * combinations Merah/M, Merah/L, Biru/M and Biru/L — in that order, so the
 * generated variants come out grouped the way the editor lists them.
 *
 * The groups keep the order they were given, and so do the values inside them.
 * That is what makes the generated names and SKUs reproducible: generating
 * twice over the same options produces the same combinations, which is what
 * lets the Action skip the ones a product already has.
 */
final class AttributeCombinations
{
    /**
     * How many combinations may be generated at once.
     *
     * Four attributes with five values each is already 625 variants, each with
     * its own SKU to key in. A product that genuinely needs more is not being
     * modelled with variants.
     */
    public const MAX_COMBINATIONS = 200;

    /**
     * @param  array<int, array<int, int>>  $groups  Attribute value ids, one inner list per attribute.
     * @return array<int, array<int, int>> One inner list of value ids per combination.
     */
    public static function cartesian(array $groups): array
    {
        $groups = array_values(array_filter($groups, fn (array $values): bool => $values !== []));

        if ($groups === []) {
            return [];
        }

        $combinations = [[]];

        foreach ($groups as $values) {
            $expanded = [];

            foreach ($combinations as $combination) {
                foreach (array_values(array_unique($values)) as $value) {
                    $expanded[] = [...$combination, $value];
                }
            }

            $combinations = $expanded;
        }

        return $combinations;
    }

    /**
     * How many combinations the given groups would produce, without building
     * them — so a request can be refused before the work is done.
     *
     * @param  array<int, array<int, int>>  $groups
     */
    public static function count(array $groups): int
    {
        $total = 1;
        $hasGroup = false;

        foreach ($groups as $values) {
            $unique = count(array_unique($values));

            if ($unique === 0) {
                continue;
            }

            $hasGroup = true;
            $total *= $unique;
        }

        return $hasGroup ? $total : 0;
    }

    /**
     * A stable key for one combination, so two lists of the same value ids
     * match however they were ordered.
     *
     * @param  array<int, int>  $valueIds
     */
    public static function fingerprint(array $valueIds): string
    {
        $sorted = array_values(array_unique(array_map('intval', $valueIds)));
        sort($sorted);

        return implode('-', $sorted);
    }
}
