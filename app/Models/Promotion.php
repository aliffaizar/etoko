<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PromotionType;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A price rule on a set of products (P2-E8-T2, ADR-067).
 *
 * @property int $id
 * @property string $name
 * @property PromotionType $type
 * @property int $value
 * @property int $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, Branch> $branches
 */
#[Fillable([
    'name', 'type', 'value', 'priority', 'starts_at', 'ends_at', 'is_active', 'created_by',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'value' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Switched on and inside its period at `$moment`.
     *
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    #[Scope]
    protected function inForce(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query->where('is_active', true)
            ->where(fn (Builder $started) => $started->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $notEnded) => $notEnded->whereNull('ends_at')->orWhere('ends_at', '>=', $moment));
    }

    /**
     * Promotions that apply at `$branch`: those open to every branch, and those
     * named for it. With no branch (the storefront's company-wide price) only
     * the open-to-all ones apply.
     *
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    #[Scope]
    protected function appliesToBranch(Builder $query, ?Branch $branch): Builder
    {
        return $query->where(function (Builder $scope) use ($branch): void {
            $scope->whereDoesntHave('branches');

            if ($branch instanceof Branch) {
                $scope->orWhereHas('branches', fn (Builder $named) => $named->whereKey($branch->getKey()));
            }
        });
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'promotion_branches');
    }
}
