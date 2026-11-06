<?php

namespace App\Models;

use Database\Factories\WishlistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product saved to a customer's wishlist (P1-E6-T1).
 *
 * This is a simple pivot: one row per (user, product) pair. The unique index
 * enforces that a product cannot be saved twice. Toggle behaviour is
 * "delete if exists, create if not".
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property-read User $user
 * @property-read Product $product
 */
#[Fillable(['user_id', 'product_id'])]
class Wishlist extends Model
{
    /** @use HasFactory<WishlistFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
