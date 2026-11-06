<?php

namespace App\Services\Pricing;

use App\Models\Branch;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The effective price of a product, as a SQL expression (P1-E5-T3, BR-8).
 *
 * `EffectivePriceResolver` is the authority on what one buyer pays for one
 * variant, and it is what every displayed figure and every snapshot comes
 * from. But the catalogue has to *sort* by price and *filter* by a range, and
 * those happen inside the query that decides which page of products to load —
 * before any of them has been resolved. Hence this: the same priority order,
 * expressed where the database can order by it.
 *
 * ```
 * 3. the branch's own price     (branch_product_prices, in force)
 * 4. the variant's price        (product_variants.price)
 * 5. the product's base price   (products.base_price)
 * ```
 *
 * Steps 1 and 2 of the resolver's order — flash sales and promotions — have no
 * tables yet; when `P1-E8` adds them they have to be added here too, in the
 * same position. `CatalogPriceQueryTest` is what holds the two in step: it
 * asserts this expression and the resolver agree on the same data, so a change
 * to one without the other fails rather than quietly making the catalogue sort
 * by a price nobody is charged.
 *
 * A product's price is the **lowest** of its active variants, which is what
 * "mulai dari" means on a card: sorting a variable product by its dearest
 * option would bury it below simple products that cost less in no useful
 * sense.
 */
final class CatalogPriceQuery
{
    /**
     * A correlated scalar subquery giving the lowest effective price of the
     * `products` row it is selected against.
     *
     * Correlated on `products.id`, so it only works inside a query over
     * `products`. Used for the catalogue's `effective_price` column and for its
     * `ORDER BY`, both of which take a subquery as a column.
     */
    public static function lowestPrice(?Branch $branch, ?Carbon $moment = null): QueryBuilder
    {
        return self::activeVariants($branch, $moment)
            ->selectRaw('min('.self::priceColumn($branch).')');
    }

    /**
     * A correlated subquery that finds the product's variants priced on one
     * side of an amount, for an `EXISTS` test.
     *
     * The price *range* filter is phrased with this rather than by comparing
     * the scalar subquery above, because a scalar subquery is not something
     * the query builder will take as the left-hand side of a `WHERE`. The two
     * questions are the same one:
     *
     * - "the cheapest option is at most X" is "**some** option is at most X";
     * - "the cheapest option is at least X" is "**no** option is below X".
     *
     * @param  '<'|'<='  $comparison
     */
    public static function variantsPriced(
        ?Branch $branch,
        string $comparison,
        int $amount,
        ?Carbon $moment = null,
    ): QueryBuilder {
        $variants = self::activeVariants($branch, $moment)->selectRaw('1');
        $column = self::priceColumn($branch);

        // Spelled out per operator so the SQL stays a literal string: an
        // interpolated operator is how an injection gets in, even when the
        // only caller passes a constant.
        return match ($comparison) {
            '<' => $variants->whereRaw($column.' < ?', [$amount]),
            '<=' => $variants->whereRaw($column.' <= ?', [$amount]),
        };
    }

    /**
     * The product's active variants, with the branch's own price joined when
     * there is a branch at all.
     *
     * Correlated on `products.id`; the caller decides what to select.
     */
    private static function activeVariants(?Branch $branch, ?Carbon $moment): QueryBuilder
    {
        $moment ??= Carbon::now();

        $variants = DB::table('product_variants as pv')
            ->whereColumn('pv.product_id', 'products.id')
            ->where('pv.is_active', true)
            ->whereNull('pv.deleted_at');

        if (! $branch instanceof Branch) {
            return $variants;
        }

        return $variants->leftJoin(
            'branch_product_prices as bpp',
            fn (JoinClause $join) => self::joinBranchPrice($join, $branch, $moment),
        );
    }

    /**
     * The effective price of one variant, as a column expression.
     *
     * This `coalesce` **is** the priority order of steps 3 to 5, and it is the
     * one place it is written in SQL.
     *
     * @return literal-string
     */
    private static function priceColumn(?Branch $branch): string
    {
        return $branch instanceof Branch
            ? 'coalesce(bpp.price, pv.price, products.base_price)'
            // No branch means no branch price to prefer, so the chain starts
            // at the variant's own price.
            : 'coalesce(pv.price, products.base_price)';
    }

    /**
     * Joins the branch's own price for the variant, and only while it is in
     * force.
     *
     * Mirrors `BranchProductPrice::inForce()`: a row that is switched off, not
     * started or already finished must not win over the catalogue price. The
     * unique index on `(branch_id, product_variant_id)` is what keeps this a
     * one-to-one join rather than fanning the rows out.
     */
    private static function joinBranchPrice(JoinClause $join, Branch $branch, Carbon $moment): void
    {
        $join->on('bpp.product_variant_id', '=', 'pv.id')
            ->where('bpp.branch_id', $branch->getKey())
            ->where('bpp.is_active', true)
            ->where(fn (JoinClause $started) => $started
                ->whereNull('bpp.starts_at')
                ->orWhere('bpp.starts_at', '<=', $moment))
            ->where(fn (JoinClause $notEnded) => $notEnded
                ->whereNull('bpp.ends_at')
                ->orWhere('bpp.ends_at', '>=', $moment));
    }
}
