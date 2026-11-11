<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of an order (P1-E7-T1).
 *
 * The descriptive columns are snapshots taken when the order was placed, not a
 * window onto the catalogue: `product_name`, `variant_name`, `sku`,
 * `image_path`, `unit_price` and `weight_gram` all say what was sold, under
 * which name, at which price (ADR-007). The `product` and `variant` relations
 * are for linking back where the row still exists — never for display, because
 * a renamed product would then rewrite the receipt.
 *
 * Deliberately **not** `Auditable`: an order line is never edited. A change to
 * what was sold is a return or a refund, which get documents of their own.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string $sku
 * @property string $product_name
 * @property string|null $variant_name
 * @property string|null $image_path
 * @property int $unit_price
 * @property int $quantity
 * @property int $discount_amount
 * @property int $subtotal
 * @property int $weight_gram
 * @property int|null $cost_price
 * @property int $refunded_quantity
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 * @property-read Collection<int, ReturnItem> $returnItems
 */
#[Fillable([
    'order_id', 'product_id', 'product_variant_id',
    'sku', 'product_name', 'variant_name', 'image_path',
    'unit_price', 'quantity', 'discount_amount', 'subtotal',
    'weight_gram', 'cost_price', 'refunded_quantity',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'discount_amount' => 'integer',
            'subtotal' => 'integer',
            'weight_gram' => 'integer',
            'cost_price' => 'integer',
            'refunded_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The line's weight, all units together.
     */
    public function totalWeightGram(): int
    {
        return $this->weight_gram * $this->quantity;
    }

    /**
     * Every return line that has ever named this order line (P2-E4-T1).
     *
     * @return HasMany<ReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    /**
     * Units on this line that have not been sent back.
     *
     * `refunded_quantity` counts units the branch has actually **taken back**
     * — it is stamped when a parcel is booked in (`ReceiveReturn`), not when a
     * return is requested. A request still in flight does not reduce what was
     * sold, because it may yet be turned down.
     */
    public function netQuantity(): int
    {
        return max($this->quantity - $this->refunded_quantity, 0);
    }

    /**
     * Units of this line already committed to a return (P2-E4-T2).
     *
     * Counted across every request that is still **alive** — requested,
     * approved, received or completed — rather than only the ones that have
     * arrived. That is what stops a customer asking for the same two units
     * back twice while the first request is still being decided; a rejected
     * or withdrawn request releases its units again, which is exactly right.
     *
     * Reads a loaded `returnItems` relation when the caller has one, so a
     * form listing twelve lines costs no query per line. Load it **with its
     * `return`** (`with('returnItems.return')`) — a return line whose header
     * is not loaded is counted rather than lazily fetched, which errs towards
     * offering the customer fewer units than they may have, and that is the
     * safe direction to be wrong in.
     */
    public function returnedQuantity(): int
    {
        $dead = [ReturnStatus::Rejected, ReturnStatus::Cancelled];

        if ($this->relationLoaded('returnItems')) {
            return (int) $this->returnItems
                ->filter(fn (ReturnItem $item): bool => $item->relationLoaded('return')
                    ? ! in_array($item->return->status, $dead, strict: true)
                    : true)
                ->sum('quantity');
        }

        return (int) $this->returnItems()
            ->whereHas('return', fn ($query) => $query->whereNotIn('status', array_map(
                fn (ReturnStatus $status): string => $status->value,
                $dead,
            )))
            ->sum('quantity');
    }

    /**
     * How many units of this line the customer may still send back.
     */
    public function returnableQuantity(): int
    {
        return max($this->quantity - $this->returnedQuantity(), 0);
    }

    /**
     * The name to show for this line: the product, and the variant when the
     * product has more than the one.
     */
    public function displayName(): string
    {
        return $this->variant_name === null || $this->variant_name === ''
            ? $this->product_name
            : "{$this->product_name} — {$this->variant_name}";
    }
}
