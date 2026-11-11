<?php

namespace App\Models;

use App\Enums\ReturnItemCondition;
use Database\Factories\ReturnItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a return — so many units of one order line (P2-E4-T1).
 *
 * Carries no snapshot of its own: the product name, the SKU and the unit price
 * are read through `orderItem`, which already holds what was sold and at what
 * price (ADR-007). Copying them here would be a second place for the same
 * receipt to be wrong.
 *
 * Deliberately **not** `Auditable`, like `OrderItem`: a return line is never
 * edited after the parcel is booked in. The one thing that happens to it after
 * that is `restocked_at` being stamped, and the ledger row it produced is
 * itself the record of that.
 *
 * @property int $id
 * @property int $return_id
 * @property int $order_item_id
 * @property int $quantity
 * @property ReturnItemCondition $condition
 * @property bool $restock
 * @property int|null $restock_warehouse_id
 * @property Carbon|null $restocked_at
 * @property string|null $notes
 * @property-read OrderReturn $return
 * @property-read OrderItem $orderItem
 * @property-read Warehouse|null $restockWarehouse
 */
#[Fillable([
    'return_id', 'order_item_id', 'quantity', 'condition', 'restock',
    'restock_warehouse_id', 'restocked_at', 'notes',
])]
class ReturnItem extends Model
{
    /** @use HasFactory<ReturnItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'condition' => ReturnItemCondition::class,
            'restock' => 'boolean',
            'restocked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function restockWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'restock_warehouse_id');
    }

    /**
     * What these units were sold for.
     *
     * The order line's own net unit price — its subtotal divided by the units
     * on it, so a line-level discount is shared across the units rather than
     * taken off the first one. Integer division truncates in the shop's
     * favour by at most a rupiah per unit, which is the right direction for a
     * figure a staff member may raise but not silently exceed.
     *
     * Zero when the order line is not loaded, so a caller that forgot to load
     * it gets a figure that is obviously wrong rather than one that is subtly
     * wrong.
     */
    public function refundableValue(): int
    {
        if (! $this->relationLoaded('orderItem')) {
            return 0;
        }

        return $this->unitValue() * $this->quantity;
    }

    /**
     * The net price of one unit of the order line this came from.
     */
    public function unitValue(): int
    {
        if (! $this->relationLoaded('orderItem')) {
            return 0;
        }

        $line = $this->orderItem;

        return $line->quantity > 0
            ? intdiv($line->subtotal, $line->quantity)
            : 0;
    }

    /**
     * Whether these units may go back on sale.
     *
     * Three things have to hold, and each of them is a real case: the branch
     * means to restock them, they came back in a sellable condition, and the
     * variant they are still exists in the catalogue. The last one is why
     * this is not simply the `restock` column — a line whose variant has been
     * hard-deleted has no shelf to go back to.
     */
    public function isRestockable(): bool
    {
        if (! $this->restock || ! $this->condition->allowsRestock()) {
            return false;
        }

        return $this->relationLoaded('orderItem')
            && $this->orderItem->product_variant_id !== null;
    }

    /**
     * Whether these units have already been put back on the shelf.
     *
     * What makes booking the same parcel in twice unwritable.
     */
    public function hasBeenRestocked(): bool
    {
        return $this->restocked_at !== null;
    }
}
