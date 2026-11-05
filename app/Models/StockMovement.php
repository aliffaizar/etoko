<?php

namespace App\Models;

use App\Concerns\BelongsToBranch;
use App\Enums\StockMovementType;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One change to on-hand stock, in the order it happened (P1-E4-T1).
 *
 * Append only: never updated, never deleted. A mistake is undone by recording
 * the opposite movement. Because of that there is no `updated_at` and the
 * model is deliberately **not** `Auditable` — the ledger already is the audit
 * trail for stock, and auditing it would store every figure twice.
 *
 * Written only by `App\Services\Inventory\StockLedger`, together with the
 * `stocks` row it produced, inside one transaction.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $branch_id
 * @property int $product_variant_id
 * @property StockMovementType $type
 * @property int $quantity_change
 * @property int $quantity_before
 * @property int $quantity_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $unit_cost
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Branch $branch
 * @property-read Warehouse $warehouse
 * @property-read ProductVariant $productVariant
 * @property-read User|null $createdBy
 * @property-read Model|null $reference
 */
#[Fillable([
    'warehouse_id', 'branch_id', 'product_variant_id', 'type', 'quantity_change',
    'quantity_before', 'quantity_after', 'reference_type', 'reference_id',
    'unit_cost', 'notes', 'created_by',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use BelongsToBranch, HasFactory;

    /**
     * The ledger records when a movement happened and nothing more; a row that
     * is never updated has no `updated_at` to keep.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity_change' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whatever caused the movement: a document, an order, or nothing at all
     * when a person adjusted the figure by hand.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        // Tie-broken by id: several movements of one posting share a timestamp
        // to the second, and a listing that reordered them between requests
        // would paginate the same row twice.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forVariant(Builder $query, int $variantId): Builder
    {
        return $query->where('product_variant_id', $variantId);
    }
}
