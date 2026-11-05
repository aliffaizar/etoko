<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use Database\Factories\BranchProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one branch decides about a centrally managed product (P1-E3-T9).
 *
 * Products belong to the whole company (BR-1); what a branch decides for
 * itself is whether it sells one. Only the deviations are stored: the absence
 * of a row means the branch follows the default, so `availabilityFor()` is the
 * only correct way to ask — a missing row is "available", not "unknown".
 *
 * Carries `BelongsToBranch`, so a branch account reading this table sees its
 * own branch's rows and nothing else (BR-3).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $product_id
 * @property bool $is_available
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Product $product
 */
#[Fillable(['branch_id', 'product_id', 'is_available', 'notes'])]
class BranchProduct extends Model
{
    /** @use HasFactory<BranchProductFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * What a branch sells when it has said nothing about a product.
     *
     * The default is deliberately "yes": a new product should reach every
     * branch's storefront without someone having to switch it on branch by
     * branch, and a new branch should start with the full catalogue.
     */
    public const DEFAULT_IS_AVAILABLE = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether a branch sells a product, reading the absence of a row as the
     * default rather than as a refusal.
     *
     * @param  BranchProduct|null  $setting  The row for this (branch, product), when there is one.
     */
    public static function availabilityFor(?self $setting): bool
    {
        if ($setting === null) {
            return self::DEFAULT_IS_AVAILABLE;
        }

        return $setting->is_available;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function unavailable(Builder $query): Builder
    {
        return $query->where('is_available', false);
    }
}
