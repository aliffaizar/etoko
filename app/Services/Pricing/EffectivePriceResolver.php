<?php

namespace App\Services\Pricing;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\ProductVariant;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The price a buyer pays for a variant at a branch (P1-E3-T10, BR-8).
 *
 * The priority order is the one in `docs/02-architecture.md` §3:
 *
 * 1. Flash sale in force          (P1-E8 — not yet a table)
 * 2. Product promotion in force   (P1-E8 — not yet a table)
 * 3. The branch's own price       (`branch_product_prices`)
 * 4. The variant's price          (`product_variants.price`)
 * 5. The product's base price     (`products.base_price`)
 *
 * The two steps that do not exist yet are in the chain already, as explicit
 * no-ops — so adding them in `P1-E8` is one method each rather than a rewrite
 * of the order, and the order itself is readable in one place.
 *
 * Vouchers are deliberately absent: a voucher applies to a basket, not to a
 * line, and belongs to the cart (`VoucherValidator`). What this returns is
 * snapshotted into `cart_items.unit_price_snapshot` and `order_items.unit_price`
 * (ADR-007), so a later change to any of the sources above never rewrites what
 * someone was charged.
 *
 * The branch is always passed in rather than read from `BranchContext`: this
 * runs for the storefront's chosen branch, for a POS session's own branch, and
 * for a report sweeping every branch, and only the caller knows which.
 */
final class EffectivePriceResolver
{
    public function for(ProductVariant $variant, ?Branch $branch = null, ?Carbon $moment = null): ResolvedPrice
    {
        $moment ??= Carbon::now();

        // Steps 1 and 2 of the order above are not tables yet; `P1-E8` adds
        // them here, ahead of the branch price.
        return $this->branchPrice($variant, $branch, $moment)
            ?? $this->catalogPrice($variant);
    }

    /**
     * Resolves many variants of one branch without a query per variant.
     *
     * The storefront's listing and the cart both price a page of variants at
     * once; asking `for()` in a loop would be one query each.
     *
     * @param  Collection<int, ProductVariant>  $variants  Each with its `product` relation loaded.
     * @return array<int, ResolvedPrice> Keyed by variant id.
     */
    public function forMany(Collection $variants, ?Branch $branch = null, ?Carbon $moment = null): array
    {
        $moment ??= Carbon::now();
        $branchPrices = $this->branchPricesFor($variants, $branch, $moment);

        $resolved = [];

        foreach ($variants as $variant) {
            $branchPrice = $branchPrices[$variant->getKey()] ?? null;

            $resolved[$variant->getKey()] = $branchPrice instanceof BranchProductPrice
                ? $this->fromBranchPrice($branchPrice, $variant)
                : $this->catalogPrice($variant);
        }

        return $resolved;
    }

    /**
     * Step 3 — the branch's own price, when there is one in force.
     */
    private function branchPrice(ProductVariant $variant, ?Branch $branch, Carbon $moment): ?ResolvedPrice
    {
        if (! $branch instanceof Branch) {
            return null;
        }

        $price = $this->branchPriceQuery()
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->inForce($moment)
            ->first();

        return $price instanceof BranchProductPrice
            ? $this->fromBranchPrice($price, $variant)
            : null;
    }

    /**
     * Steps 4 and 5 — the variant's own price, or the product's base price.
     *
     * `ProductVariant::effectivePrice()` already holds that fallback; this
     * only has to say which of the two answered, which is what the source is
     * for.
     */
    private function catalogPrice(ProductVariant $variant): ResolvedPrice
    {
        return new ResolvedPrice(
            amount: $variant->effectivePrice(),
            source: $variant->price === null
                ? PriceSource::ProductBasePrice
                : PriceSource::VariantPrice,
            compareAtPrice: $variant->effectiveCompareAtPrice(),
        );
    }

    /**
     * A branch price with no strikethrough of its own falls back to the
     * catalogue's, so a branch that only lowers the price still advertises the
     * saving.
     */
    private function fromBranchPrice(BranchProductPrice $price, ProductVariant $variant): ResolvedPrice
    {
        return new ResolvedPrice(
            amount: $price->price,
            source: PriceSource::BranchPrice,
            compareAtPrice: $price->compare_at_price ?? $variant->effectiveCompareAtPrice(),
        );
    }

    /**
     * The branch prices in force for a set of variants, keyed by variant id.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, BranchProductPrice>
     */
    private function branchPricesFor(Collection $variants, ?Branch $branch, Carbon $moment): array
    {
        if (! $branch instanceof Branch || $variants->isEmpty()) {
            return [];
        }

        return $this->branchPriceQuery()
            ->where('branch_id', $branch->getKey())
            ->whereIn('product_variant_id', $variants->modelKeys())
            ->inForce($moment)
            ->get()
            ->keyBy('product_variant_id')
            ->all();
    }

    /**
     * Branch prices without the branch global scope.
     *
     * The branch is an argument here, not the actor's own: the storefront
     * prices the branch a customer picked, and a central report prices every
     * branch in turn. Leaving `BranchScope` on would narrow those to the
     * actor's branch — or to nothing at all for a customer, who has none.
     *
     * @return Builder<BranchProductPrice>
     */
    private function branchPriceQuery(): Builder
    {
        return BranchProductPrice::query()->withoutGlobalScope(BranchScope::class);
    }
}
