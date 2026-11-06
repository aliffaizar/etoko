<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line in a shopping cart (P1-E6-T1).
 *
 * `unit_price_snapshot` is captured from `EffectivePriceResolver` when the
 * item is added, so the displayed price stays stable even while the customer
 * browses. It is re-validated (and potentially updated) at checkout time.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property int $unit_price_snapshot
 * @property string|null $notes
 * @property-read Cart $cart
 * @property-read ProductVariant $variant
 */
#[Fillable(['cart_id', 'product_variant_id', 'quantity', 'unit_price_snapshot', 'notes'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Line total in rupiah.
     */
    public function lineTotal(): int
    {
        return $this->unit_price_snapshot * $this->quantity;
    }
}
