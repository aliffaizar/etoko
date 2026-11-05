<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use Database\Factories\BranchProductPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A branch's own price for one variant (P1-E3-T10).
 *
 * Priced per variant rather than per product, because a cart line and an order
 * line both carry a variant id: a price that could not be told apart per
 * variant could not be charged.
 *
 * Being stored is not the same as being in force. A row can be switched off,
 * or scheduled to start and end, so `isInForce()` is the only correct way to
 * ask — and `EffectivePriceResolver` is what weighs it against the variant's
 * and the product's own price (BR-8).
 *
 * Carries `BelongsToBranch`, so a branch account reading this table sees its
 * own branch's prices and nothing else (BR-3).
 *
 * Money is whole rupiah integers (ADR-008, BR-10).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $product_variant_id
 * @property int $price
 * @property int|null $compare_at_price
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read ProductVariant $variant
 */
#[Fillable([
    'branch_id', 'product_variant_id', 'price', 'compare_at_price',
    'starts_at', 'ends_at', 'is_active',
])]
class BranchProductPrice extends Model
{
    /** @use HasFactory<BranchProductPriceFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Whether this price applies at the given moment.
     *
     * Mirrors the `inForce` scope in PHP; the two are two sides of one
     * definition and the resolver's tests hold them to it.
     */
    public function isInForce(?Carbon $moment = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $moment ??= Carbon::now();

        if ($this->starts_at !== null && $this->starts_at->isAfter($moment)) {
            return false;
        }

        return $this->ends_at === null || ! $this->ends_at->isBefore($moment);
    }

    /**
     * How this price stands right now, for the branch price screen.
     */
    public function state(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * Prices in force at the given moment.
     *
     * Mirrors `isInForce()` in SQL.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function inForce(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= Carbon::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $started) => $started
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $notEnded) => $notEnded
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $moment));
    }
}
