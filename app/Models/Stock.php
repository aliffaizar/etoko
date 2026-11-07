<?php

namespace App\Models;

use App\Concerns\BelongsToBranch;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How much of one variant sits in one warehouse (P1-E4-T1).
 *
 * A row is created the first time something is said about the pair, so the
 * absence of a row means zero rather than unknown. Ask through
 * `Stock::orZeroed()` rather than reading a possibly-missing row, the same way
 * `BranchProduct::availabilityFor()` reads a missing availability row as the
 * default.
 *
 * `quantity`, `reserved_quantity` and the ledger are written **only** by
 * `App\Services\Inventory\StockLedger`, which keeps `stocks` and
 * `stock_movements` in step inside one transaction. Nothing else may write
 * them — a figure changed without a ledger row is a figure nobody can explain.
 * `min_quantity`, `max_quantity` and `shelf_location` are plain settings and
 * are written by `SetStockThresholds`.
 *
 * **Not `Auditable`**, for the reason ADR-024 already gives `StockMovement`:
 * the ledger *is* the audit trail for stock, and auditing the figure it
 * explains stores every number twice and gives reconciliation two sources that
 * can disagree (MED-05). It was audited until now, which cost an extra
 * `INSERT` with a JSON before/after payload on every reservation, every sale
 * and every posted document line — inside the transaction that is holding the
 * stock row's lock, so it also lengthened the window every checkout contends
 * for. The threshold columns are the only ones that are not derivable from the
 * ledger, and their history can be restored by auditing just those if it is
 * ever wanted.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $branch_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property int $reserved_quantity
 * @property int $min_quantity
 * @property int|null $max_quantity
 * @property string|null $shelf_location
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $available_quantity
 * @property-read Branch $branch
 * @property-read Warehouse $warehouse
 * @property-read ProductVariant $productVariant
 */
#[Fillable([
    'warehouse_id', 'branch_id', 'product_variant_id', 'quantity',
    'reserved_quantity', 'min_quantity', 'max_quantity', 'shelf_location',
])]
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
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
     * What may still be sold: on-hand minus what checkout is holding (BR-6).
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function availableQuantity(): Attribute
    {
        return Attribute::get(fn (): int => max($this->quantity - $this->reserved_quantity, 0));
    }

    /**
     * Whether this row has fallen to or below its own minimum (P1-E4-T9).
     *
     * A minimum of 0 means nobody set one, so nothing is "low": a variant a
     * branch does not stock on purpose must not raise an alert for ever.
     */
    public function isLow(): bool
    {
        return $this->min_quantity > 0 && $this->quantity <= $this->min_quantity;
    }

    /**
     * A row that stands for "nothing has been said about this pair yet".
     *
     * Rows are created on demand, so a (warehouse, variant) pair without one
     * holds zero rather than being unknown. Reading through this keeps every
     * caller from re-deciding what a missing row means — and from writing
     * `$stock?->quantity ?? 0` five times with one of them wrong.
     *
     * Never saved. It carries no keys, so anything that tried would fail.
     */
    public static function zeroed(): self
    {
        return new self([
            'quantity' => 0,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'max_quantity' => null,
            'shelf_location' => null,
        ]);
    }

    /**
     * The row for a pair, or a zeroed stand-in when there is none.
     */
    public static function orZeroed(?self $stock): self
    {
        return $stock ?? self::zeroed();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function low(Builder $query): Builder
    {
        return $query->where('min_quantity', '>', 0)
            ->whereColumn('quantity', '<=', 'min_quantity');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}
