<?php

namespace App\Models;

use Database\Factories\VoucherUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One order's redemption of a voucher (P2-E5-T4).
 *
 * Written only by `RedeemVoucher`, inside `PlaceOrder`'s transaction and under
 * the voucher's row lock; released only by `ReleaseVoucher` when the order is
 * cancelled or expires. A released row stays as history and stops counting
 * against the quota and the customer's allowance (`scopeLive()`).
 *
 * @property int $id
 * @property int $voucher_id
 * @property int|null $user_id
 * @property int $order_id
 * @property int $discount_amount
 * @property Carbon $used_at
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Voucher $voucher
 * @property-read User|null $user
 * @property-read Order $order
 */
#[Fillable(['voucher_id', 'user_id', 'order_id', 'discount_amount', 'used_at', 'released_at'])]
class VoucherUsage extends Model
{
    /** @use HasFactory<VoucherUsageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'used_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Usages that still count against the quota and the customer's allowance.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function live(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
