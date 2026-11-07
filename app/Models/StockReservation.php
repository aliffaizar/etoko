<?php

namespace App\Models;

use App\Concerns\BelongsToBranch;
use App\Enums\ReservationStatus;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock promised to one buyer and not yet handed over (P1-E7-T1, BR-6).
 *
 * `stocks.reserved_quantity` is the total being held; these rows say who holds
 * what, which is what makes a hold releasable. The two are written together by
 * `ReserveStock`, `CommitReservation` and `ReleaseReservation` — all three
 * reach the figures through `StockLedger`, the only writer of `stocks`
 * (ADR-024).
 *
 * Rows are never deleted and `status` only moves away from `Active`, so a hold
 * that vanished always has an explanation. Both actions act on `Active` rows
 * only, which is what makes releasing the same hold twice unwritable.
 *
 * Deliberately **not** `Auditable`: like the stock ledger, it already is the
 * record of what happened to the figure.
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $cart_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int $branch_id
 * @property int $quantity
 * @property ReservationStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read Cart|null $cart
 * @property-read Branch $branch
 * @property-read Warehouse $warehouse
 * @property-read ProductVariant $variant
 */
#[Fillable([
    'order_id', 'cart_id', 'product_variant_id', 'warehouse_id', 'branch_id',
    'quantity', 'status', 'expires_at',
])]
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isHolding(): bool
    {
        return $this->status->isHolding();
    }

    /**
     * Whether this hold's moment has passed.
     *
     * A hold with no `expires_at` never expires on its own: it is released when
     * its order is cancelled, and committed when the order is paid.
     */
    public function hasExpired(?Carbon $moment = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($moment ?? Carbon::now());
    }

    /**
     * Holds that still occupy `stocks.reserved_quantity`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Active);
    }

    /**
     * Active holds whose moment has passed — what `ExpireStockReservations`
     * sweeps, and what the `(status, expires_at)` index exists for.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function expired(Builder $query, ?Carbon $moment = null): Builder
    {
        return $query->active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment ?? Carbon::now());
    }
}
