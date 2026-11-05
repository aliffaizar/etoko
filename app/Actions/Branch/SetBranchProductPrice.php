<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Sets or clears a branch's own price for one variant (P1-E3-T10).
 *
 * One row per (branch, variant), so setting a price that already exists
 * updates it rather than stacking a second one — which is also what the unique
 * index guarantees.
 *
 * Clearing is a delete, not a zero: a row with price 0 would mean the branch
 * gives the variant away, while no row means the branch charges what the
 * catalogue charges. Those are very different answers and the table must not
 * be able to confuse them.
 */
final class SetBranchProductPrice
{
    /**
     * @param  array<string, mixed>  $attributes  Validated price, compare_at_price, starts_at, ends_at and is_active.
     */
    public function handle(Branch $branch, ProductVariant $variant, array $attributes): BranchProductPrice
    {
        return DB::transaction(function () use ($branch, $variant, $attributes): BranchProductPrice {
            $price = BranchProductPrice::query()
                ->where('branch_id', $branch->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->first();

            if ($price instanceof BranchProductPrice) {
                $price->fill($attributes)->save();

                return $price;
            }

            return BranchProductPrice::create([
                ...$attributes,
                'branch_id' => $branch->getKey(),
                'product_variant_id' => $variant->getKey(),
            ]);
        });
    }

    /**
     * Removes a branch's price, putting the variant back on the catalogue's.
     */
    public function clear(BranchProductPrice $price): void
    {
        $price->delete();
    }
}
