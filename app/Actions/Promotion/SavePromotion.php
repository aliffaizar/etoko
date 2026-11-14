<?php

namespace App\Actions\Promotion;

use App\Actions\Product\RefreshLowestPrice;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a promotion with its product and branch lists
 * (P2-E8-T2, ADR-067).
 *
 * One action for both, as with `SaveVoucher`: the row and its two lists are
 * written in one transaction. Prices are refreshed **after** the lists are in
 * place, for the union of the products the promotion covered before and after,
 * so a product dropped from a promotion goes back to its ordinary price on the
 * storefront at once.
 */
final class SavePromotion
{
    public function __construct(private readonly RefreshLowestPrice $refreshLowestPrice) {}

    /**
     * @param  array{attributes: array<string, mixed>, product_ids: array<int, int>, branch_ids: array<int, int>}  $data
     */
    public function handle(array $data, ?Promotion $promotion = null, ?User $actor = null): Promotion
    {
        return DB::transaction(function () use ($data, $promotion, $actor): Promotion {
            $attributes = $data['attributes'];

            $previousProductIds = $promotion instanceof Promotion
                ? $promotion->products()->pluck('products.id')->map(fn ($id): int => (int) $id)->all()
                : [];

            if ($promotion instanceof Promotion) {
                $promotion->update($attributes);
            } else {
                $attributes['created_by'] = $actor?->getKey();
                $promotion = Promotion::query()->create($attributes);
            }

            $promotion->products()->sync($data['product_ids']);
            $promotion->branches()->sync($data['branch_ids']);

            $this->refreshLowestPrice->handle(array_values(array_unique([
                ...$previousProductIds,
                ...$data['product_ids'],
            ])));

            return $promotion;
        });
    }
}
