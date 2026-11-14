<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shopping cart (P1-E6-T1).
 *
 * Supports both authenticated users and guests:
 * - Authenticated: `user_id` is set, `session_token` is null.
 * - Guest: `user_id` is null, `session_token` is a random token stored in a
 *   cookie/session. Guest carts expire via `expires_at`.
 *
 * When a guest logs in their cart is merged into the user's cart (T5).
 *
 * `branch_id` stores the branch the customer chose during browsing — it
 * determines which stock figures are shown at checkout. It may be null until
 * the customer has explicitly chosen a branch.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_token
 * @property int|null $branch_id
 * @property int|null $voucher_id
 * @property FulfillmentType|null $fulfillment_type
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Branch|null $branch
 * @property-read Voucher|null $voucher
 * @property-read Collection<int, CartItem> $items
 */
#[Fillable(['user_id', 'session_token', 'branch_id', 'voucher_id', 'fulfillment_type', 'expires_at'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'fulfillment_type' => FulfillmentType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The voucher applied on the cart screen (P2-E5-T3). Re-checked by
     * `PlaceOrder`; holding it here promises nothing.
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Total items count across all cart lines.
     */
    public function totalQuantity(): int
    {
        return $this->items->sum('quantity');
    }

    /**
     * Subtotal in rupiah (snapshot prices × quantities).
     */
    public function subtotal(): int
    {
        return $this->items->sum(fn (CartItem $item) => $item->unit_price_snapshot * $item->quantity);
    }
}
