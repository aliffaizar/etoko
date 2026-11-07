<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer order (P1-E7-T1).
 *
 * Branch-scoped like every other transactional table (BR-3): branch staff see
 * their branch's orders and nobody else's, and because `SubstituteBindings`
 * runs after `SetBranchContext` (ADR-027) another branch's order number in the
 * URL answers 404 rather than 403.
 *
 * Status is only ever changed by `App\Actions\Order\TransitionOrderStatus`,
 * which checks the move against `OrderStatus::allowedTransitions()`, writes the
 * `order_status_histories` row, stamps the matching timestamp, and releases or
 * commits the stock the order is holding. Writing `status` directly skips all
 * four.
 *
 * Every descriptive and monetary column is a snapshot of checkout, not a view
 * onto current master data (ADR-007).
 *
 * @property int $id
 * @property string $order_number
 * @property int|null $user_id
 * @property int $branch_id
 * @property OrderChannel $channel
 * @property FulfillmentType $fulfillment_type
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $shipping_recipient
 * @property string|null $shipping_phone
 * @property string|null $shipping_address_line
 * @property string|null $shipping_district_name
 * @property string|null $shipping_city_name
 * @property string|null $shipping_province_name
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_latitude
 * @property string|null $shipping_longitude
 * @property int $subtotal
 * @property int $discount_total
 * @property int $shipping_cost
 * @property int $tax_total
 * @property int $grand_total
 * @property int $total_weight_gram
 * @property int|null $voucher_id
 * @property string|null $voucher_code
 * @property string|null $customer_note
 * @property string|null $internal_note
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read User|null $user
 * @property-read User|null $createdBy
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistories
 * @property-read Collection<int, StockReservation> $stockReservations
 */
#[Fillable([
    'order_number', 'user_id', 'branch_id', 'channel', 'fulfillment_type',
    'status', 'payment_status',
    'customer_name', 'customer_email', 'customer_phone',
    'shipping_recipient', 'shipping_phone', 'shipping_address_line',
    'shipping_district_name', 'shipping_city_name', 'shipping_province_name',
    'shipping_postal_code', 'shipping_latitude', 'shipping_longitude',
    'subtotal', 'discount_total', 'shipping_cost', 'tax_total', 'grand_total',
    'total_weight_gram', 'voucher_id', 'voucher_code',
    'customer_note', 'internal_note',
    'placed_at', 'paid_at', 'processed_at', 'shipped_at', 'completed_at',
    'cancelled_at', 'cancel_reason', 'created_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use Auditable, BelongsToBranch, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'fulfillment_type' => FulfillmentType::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'shipping_cost' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'total_weight_gram' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'processed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The order's status timeline, oldest first — the sequence it happened in.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Units on the order, across every line.
     *
     * Reuses an `items_sum_quantity` the caller already loaded with
     * `withSum('items', 'quantity')`, so a listing costs no query per row.
     */
    public function totalQuantity(): int
    {
        $summed = $this->getAttribute('items_sum_quantity');

        if ($summed !== null) {
            return (int) $summed;
        }

        return $this->relationLoaded('items')
            ? (int) $this->items->sum('quantity')
            : (int) $this->items()->sum('quantity');
    }

    /**
     * Whether the customer may still call this order off themselves.
     *
     * Deliberately narrower than what an admin may do: once a branch has begun
     * picking the order, calling it off is a conversation, not a button
     * (P1-E7-T9).
     */
    public function isCancellableByCustomer(): bool
    {
        return $this->status === OrderStatus::PendingPayment;
    }

    /**
     * Why this order may not be cancelled, or null when it may be.
     *
     * One source for the endpoint's refusal and for the `can.cancel` flag the
     * screens read, so a button is never offered for something the server will
     * refuse — the pattern `Branch::deletionBlockedReason()` set.
     */
    public function cancellationBlockedReason(bool $asCustomer): ?string
    {
        if (! $this->status->canTransitionTo(OrderStatus::Cancelled)) {
            return "Pesanan berstatus \"{$this->status->label()}\" tidak dapat dibatalkan.";
        }

        if ($asCustomer && ! $this->isCancellableByCustomer()) {
            return 'Pesanan sudah mulai diproses. Hubungi cabang untuk membatalkannya.';
        }

        return null;
    }

    /**
     * Why the processing branch may not be moved, or null when it may be.
     *
     * Moving an order means moving the stock it holds (P1-E7-T10), and stock
     * that has already left the shelf cannot be un-picked by changing a column.
     */
    public function branchReassignmentBlockedReason(): ?string
    {
        if ($this->status->isFinal()) {
            return "Pesanan berstatus \"{$this->status->label()}\" sudah selesai, cabangnya tidak dapat dipindahkan.";
        }

        if (in_array($this->status, [OrderStatus::Shipped, OrderStatus::Delivered], strict: true)) {
            return 'Pesanan sudah dikirim, jadi cabang pemrosesnya tidak dapat dipindahkan.';
        }

        return null;
    }

    /**
     * The moment column that records an order reaching the given status.
     *
     * Returns null for statuses that have no column of their own; their record
     * is the history row, which every transition writes regardless.
     */
    public static function timestampColumnFor(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::Paid => 'paid_at',
            OrderStatus::Processing => 'processed_at',
            OrderStatus::Shipped => 'shipped_at',
            OrderStatus::Completed => 'completed_at',
            OrderStatus::Cancelled, OrderStatus::Expired => 'cancelled_at',
            default => null,
        };
    }

    /**
     * Orders still awaiting something from the branch.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (OrderStatus $status): string => $status->value,
            OrderStatus::openStatuses(),
        ));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
