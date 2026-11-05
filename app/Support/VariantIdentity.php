<?php

namespace App\Support;

use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Names and SKUs for generated variants (P1-E3-T6).
 *
 * A generator that asked for 24 SKUs before it would create anything would
 * never be used. It derives them instead — "KPS-16" plus Merah and XL becomes
 * `KPS-16-MERAH-XL`, named "Merah / XL" — and every one of them stays editable
 * afterwards, because a derived SKU is a starting point and not a rule.
 *
 * Uniqueness is checked against the table, soft-deleted variants included: a
 * deleted variant keeps its SKU because order history still points at it.
 */
final class VariantIdentity
{
    /** Longest SKU the column holds. */
    private const SKU_MAX_LENGTH = 60;

    /**
     * How a variant reads in a list: its option labels, separated by a slash.
     *
     * @param  array<int, string>  $valueLabels
     */
    public static function nameFrom(array $valueLabels): ?string
    {
        $labels = array_values(array_filter(array_map('trim', $valueLabels), fn (string $label): bool => $label !== ''));

        return $labels === [] ? null : implode(' / ', $labels);
    }

    /**
     * A free SKU built from a base and the variant's option labels.
     *
     * @param  array<int, string>  $valueLabels
     * @param  array<int, string>  $alreadyTaken  SKUs claimed earlier in the same generation run, which are not in the table yet.
     */
    public static function skuFrom(string $base, array $valueLabels, array $alreadyTaken = []): string
    {
        $parts = array_map(
            fn (string $label): string => self::slugSegment($label),
            $valueLabels,
        );

        $candidate = self::truncate(implode('-', array_filter([self::slugSegment($base), ...$parts])));

        if ($candidate === '') {
            $candidate = 'SKU';
        }

        return self::firstFree($candidate, $alreadyTaken);
    }

    /**
     * Suffixes a SKU until it is not taken, by the table or by the run in
     * progress.
     *
     * @param  array<int, string>  $alreadyTaken
     */
    private static function firstFree(string $candidate, array $alreadyTaken): string
    {
        $taken = array_map('mb_strtoupper', $alreadyTaken);
        $sku = $candidate;
        $suffix = 1;

        while (in_array($sku, $taken, true) || self::existsInTable($sku)) {
            $suffix++;
            $sku = self::truncate($candidate, mb_strlen((string) $suffix) + 1).'-'.$suffix;
        }

        return $sku;
    }

    private static function existsInTable(string $sku): bool
    {
        return ProductVariant::withTrashed()->where('sku', $sku)->exists();
    }

    /**
     * The SKU alphabet is the one the form requests validate:
     * `A-Z`, `0-9`, `.`, `_` and `-`.
     */
    private static function slugSegment(string $value): string
    {
        return mb_strtoupper(Str::slug($value, '-'));
    }

    private static function truncate(string $value, int $reserve = 0): string
    {
        return rtrim(mb_substr($value, 0, self::SKU_MAX_LENGTH - $reserve), '-');
    }
}
