<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of an order's timeline (P1-E7-T1).
 *
 * Append-only, like `stock_movements`: written by `TransitionOrderStatus` and
 * never updated or deleted, so `UPDATED_AT` is disabled — a row that can be
 * rewritten is not a trail. Reversing a status is another row, not an edit.
 *
 * Deliberately **not** `Auditable` for the same reason the stock ledger is not:
 * it already *is* the audit trail of an order's status.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $note
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 * @property-read Order $order
 * @property-read User|null $changedBy
 */
#[Fillable(['order_id', 'from_status', 'to_status', 'note', 'changed_by', 'created_at'])]
class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
