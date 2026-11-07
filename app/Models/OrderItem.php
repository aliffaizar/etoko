<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Units on this line that have not been sent back.
     */
    public function netQuantity(): int
    {
        return max($this->quantity - $this->refunded_quantity, 0);
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
