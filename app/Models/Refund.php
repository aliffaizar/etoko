<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of money back to the customer (P2-E4-T1, T4).
 *
 * Branch-scoped like every other transactional table (BR-3) through a
 * `branch_id` copied from the order (ADR-038), so another branch's refund id
 * in the URL answers 404 rather than 403 (ADR-027). Branch staff cannot
 * *make* a refund — `refunds.process` is central (docs/04-rbac.md) — but they
 * do have to see what went back against their own orders.
 *
 * Status is only ever changed by the actions in `App\Actions\Returns`, each of
 * which re-reads the row under its lock. Writing `status` directly skips the
 * order-level consequence: `orders.payment_status` becoming
 * `partially_refunded` or `refunded`, the attempt the money came in on being
 * marked refunded, and the return it belongs to being closed.
 *
 * `method` is a snapshot of the decision, not a view onto configuration
 * (ADR-007): "this went back by bank transfer" must stay true however the
 * shop's payment methods change afterwards.
 *
 * @property int $id
 * @property string $code
 * @property int $order_id
 * @property int|null $return_id
 * @property int|null $payment_id
 * @property int $branch_id
 * @property int $amount
 * @property RefundMethod $method
 * @property RefundStatus $status
 * @property string|null $reference
 * @property string|null $account_info
 * @property string|null $failure_reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $processed_by
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read OrderReturn|null $return
 * @property-read Payment|null $payment
 * @property-read User|null $createdBy
 * @property-read User|null $processedBy
 */
#[Fillable([
    'code', 'order_id', 'return_id', 'payment_id', 'branch_id',
    'amount', 'method', 'status', 'reference', 'account_info',
    'failure_reason', 'notes', 'created_by', 'processed_by', 'processed_at',
])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => RefundMethod::class,
            'status' => RefundStatus::class,
            'processed_at' => 'datetime',
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
     * @return BelongsTo<OrderReturn, $this>
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Whether the money has actually gone back.
     */
    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Whether somebody still has to finish this refund.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Why this refund may not be settled or failed by hand, or null when it
     * may be.
     *
     * One source for the endpoint's refusal and for the `can.settle` flag the
     * screens read, so a button is never offered for something the server will
     * turn down.
     */
    public function settlementBlockedReason(): ?string
    {
        if ($this->isOpen()) {
            return null;
        }

        return "Refund ini sudah berstatus \"{$this->status->label()}\" dan tidak dapat diubah lagi.";
    }

    /**
     * Refunds whose money has gone back — the only ones that count towards
     * how much of an order has been refunded.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function settled(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Processed->value);
    }

    /**
     * Refunds somebody still has to finish.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Pending->value);
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
