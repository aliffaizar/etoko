<?php

namespace App\Services\Pricing;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The price a buyer pays for a variant at a branch (P1-E3-T10, BR-8).
 *
 * The priority order is the one in `docs/02-architecture.md` §3:
 *
 * 1. Flash sale in force          (not yet a table — no epic plans it)
 * 2. Product promotion in force   (`promotions`, P2-E8-T2, ADR-067)
 * 3. The branch's own price       (`branch_product_prices`)
 * 4. The variant's price          (`product_variants.price`)
 * 5. The product's base price     (`products.base_price`)
 *
 * A promotion takes its cut off the catalogue price (steps 4–5), not off the
 * branch's own price, and it outranks that price: a promotion is the company's
 * offer, a branch price is the branch's ordinary price, and the offer is the
 * one a shopper is shown.
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
        $catalog = $this->catalogPrice($variant);
        $promotion = $this->bestPromotions(collect([$variant]), $branch, $moment)[$variant->product_id] ?? null;

        return $this->promotionPrice($catalog, $promotion)
            ?? $this->branchPrice($variant, $branch, $moment)
            ?? $catalog;
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
        $promotions = $this->bestPromotions($variants, $branch, $moment);

        $resolved = [];

        foreach ($variants as $variant) {
            $catalog = $this->catalogPrice($variant);
            $branchPrice = $branchPrices[$variant->getKey()] ?? null;

            $resolved[$variant->getKey()] = $this->promotionPrice($catalog, $promotions[$variant->product_id] ?? null)
                ?? ($branchPrice instanceof BranchProductPrice
                    ? $this->fromBranchPrice($branchPrice, $variant)
                    : $catalog);
        }

        return $resolved;
    }

    /**
     * Step 2 — the promotion that wins for each product, keyed by product id.
     *
     * Of the promotions in force that apply at `$branch` and name the product,
     * the highest `priority` wins, and a tie goes to the older promotion (lower
     * id), so the answer never depends on the order rows come back in.
     *
     * @param  SupportCollection<int, ProductVariant>  $variants
     * @return array<int, Promotion> Keyed by product id.
     */
    private function bestPromotions(SupportCollection $variants, ?Branch $branch, Carbon $moment): array
    {
        $productIds = $variants->pluck('product_id')->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $promotions = Promotion::query()
            ->inForce($moment)
            ->appliesToBranch($branch)
            ->whereHas('products', fn (Builder $products) => $products->whereIn('products.id', $productIds))
            ->with(['products' => fn ($products) => $products->whereIn('products.id', $productIds)->select('products.id')])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $best = [];

        foreach ($promotions as $promotion) {
            foreach ($promotion->products as $product) {
                $best[$product->getKey()] ??= $promotion;
            }
        }

        return $best;
    }

    /**
     * The price with a promotion taken off the catalogue price, or null when
     * there is no promotion or it takes nothing off.
     */
    private function promotionPrice(ResolvedPrice $catalog, ?Promotion $promotion): ?ResolvedPrice
    {
        if (! $promotion instanceof Promotion) {
            return null;
        }

        $discount = $promotion->type->discountFor($catalog->amount, $promotion->value);

        if ($discount === 0) {
            return null;
        }

        return new ResolvedPrice(
            amount: $catalog->amount - $discount,
            source: PriceSource::Promotion,
            compareAtPrice: $catalog->compareAtPrice ?? $catalog->amount,
        );
    }

    /**
     * Step 4 and 5 as a `ResolvedPrice`: the variant's own price, or the
     * product's base price, with the source recorded for the snapshot.
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
