<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use Database\Factories\OrderReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One request to send an order's goods back (P2-E4-T1).
 *
 * Named `OrderReturn` rather than `Return`, which PHP will not accept as a
 * class name, and the table keeps its documented name `returns` (ADR-046).
 *
 * Branch-scoped like every other transactional table (BR-3) through a
 * `branch_id` copied from the order (ADR-038), so another branch's return id
 * in the URL answers 404 rather than 403 (ADR-027).
 *
 * Status is only ever changed by the actions in `App\Actions\Returns`, each of
 * which re-reads the row under its lock. Writing `status` directly skips the
 * consequence the status carries: `received` is the moment goods go back on
 * the shelf as `sale_return_in` and `order_items.refunded_quantity` goes up,
 * and `completed` is the moment the money has been accounted for.
 *
 * Nothing descriptive is copied onto this row. The product names, the SKUs and
 * the prices are read through `items.orderItem`, which already holds the
 * snapshots taken at checkout (ADR-007) — copying them again would be a second
 * place for the same receipt to be wrong.
 *
 * @property int $id
 * @property string $code
 * @property int $order_id
 * @property int|null $user_id
 * @property int $branch_id
 * @property ReturnType $type
 * @property ReturnReason $reason
 * @property string|null $description
 * @property array<int, string>|null $photo_paths
 * @property ReturnStatus $status
 * @property Carbon|null $requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $received_by
 * @property Carbon|null $received_at
 * @property Carbon|null $completed_at
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read User|null $user
 * @property-read User|null $approvedBy
 * @property-read User|null $receivedBy
 * @property-read Collection<int, ReturnItem> $items
 * @property-read Collection<int, Refund> $refunds
 */
#[Fillable([
    'code', 'order_id', 'user_id', 'branch_id', 'type', 'reason',
    'description', 'photo_paths', 'status', 'requested_at',
    'approved_by', 'approved_at', 'received_by', 'received_at', 'completed_at',
    'rejection_reason', 'notes',
])]
class OrderReturn extends Model
{
    /** @use HasFactory<OrderReturnFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * The documented table name (docs/03-data-model.md §7).
     *
     * Stated explicitly because Eloquent would derive `order_returns` from the
     * class name, and the class name is only what it is because `Return` is a
     * reserved word.
     */
    protected $table = 'returns';

    /** Where the photos a customer attaches to a request live on the disk. */
    public const PHOTO_DIRECTORY = 'return-photos';

    /** The disk those photos are written to. */
    public const PHOTO_DISK = 'public';

    /** How many photos one request may carry. */
    public const MAX_PHOTOS = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReturnType::class,
            'reason' => ReturnReason::class,
            'status' => ReturnStatus::class,
            'photo_paths' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id')->orderBy('id');
    }

    /**
     * The money sent back against this return, newest first.
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'return_id')->orderByDesc('id');
    }

    /**
     * Units on the request, across every line.
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
     * What the goods on this request were sold for, line by line.
     *
     * The basis of what may be refunded: each line's share of its order line,
     * at the unit price the customer actually paid after the line's own
     * discount. Read from the order line's snapshot (ADR-007), never from the
     * catalogue.
     */
    public function goodsValue(): int
    {
        return $this->items
            ->map(fn (ReturnItem $item): int => $item->refundableValue())
            ->sum();
    }

    /**
     * How much has actually gone back against this return.
     *
     * Only `processed` refunds count (`RefundStatus`): a reversal still in
     * flight is money the shop has promised, not money the customer has.
     */
    public function refundedAmount(): int
    {
        if ($this->relationLoaded('refunds')) {
            return (int) $this->refunds
                ->filter(fn (Refund $refund): bool => $refund->status->isSettled())
                ->sum('amount');
        }

        return (int) $this->refunds()->settled()->sum('amount');
    }

    /**
     * Whether the goods have come back and been counted.
     */
    public function goodsAreBack(): bool
    {
        return $this->status->goodsAreBack();
    }

    /**
     * Why this request may not be approved, or null when it may be.
     *
     * One source for the endpoint's refusal and for the `can.approve` flag the
     * screens read, so a button is never offered for something the server will
     * turn down — the pattern `Branch::deletionBlockedReason()` set.
     */
    public function approvalBlockedReason(): ?string
    {
        return $this->transitionBlockedReason(ReturnStatus::Approved);
    }

    /**
     * Why this request may not be turned down, or null when it may be.
     */
    public function rejectionBlockedReason(): ?string
    {
        return $this->transitionBlockedReason(ReturnStatus::Rejected);
    }

    /**
     * Why the goods may not be booked in, or null when they may be.
     *
     * Deliberately **not** a stock check: whether the shelf can take the units
     * back is always yes, and whether a particular line *may* be restocked is
     * decided line by line when the parcel is opened.
     */
    public function receivingBlockedReason(): ?string
    {
        return $this->transitionBlockedReason(ReturnStatus::Received);
    }

    /**
     * Why the customer may not withdraw this request, or null when they may.
     *
     * A customer may call it off while nobody has acted on it. Once the branch
     * has the goods, withdrawing is a conversation rather than a button — the
     * same line `Order::isCancellableByCustomer()` draws.
     */
    public function customerCancellationBlockedReason(): ?string
    {
        if ($this->status !== ReturnStatus::Requested) {
            return "Retur berstatus \"{$this->status->label()}\" tidak dapat dibatalkan sendiri. "
                .'Hubungi cabang bila perlu dibatalkan.';
        }

        return null;
    }

    /**
     * Why money may not yet go back against this return, or null when it may.
     *
     * The shop pays for goods it **has**, which is why this asks for
     * `received` and not merely `approved`: an approval is a promise, and a
     * refund paid against a promise is a refund paid for a parcel that may
     * never arrive.
     */
    public function refundBlockedReason(): ?string
    {
        if (! $this->type->endsInRefund()) {
            return 'Retur ini berjenis tukar barang, jadi tidak ada dana yang dikembalikan. '
                .'Terbitkan pesanan pengganti untuk pelanggan.';
        }

        if (! $this->goodsAreBack()) {
            return "Barang retur belum diterima di cabang (status \"{$this->status->label()}\"). "
                .'Catat penerimaan barang terlebih dahulu sebelum mengembalikan dana.';
        }

        return null;
    }

    /**
     * Why this request may not move to `$to`, or null when it may.
     */
    private function transitionBlockedReason(ReturnStatus $to): ?string
    {
        if ($this->status->canTransitionTo($to)) {
            return null;
        }

        if ($this->status === $to) {
            return "Retur ini sudah berstatus \"{$to->label()}\".";
        }

        return "Retur berstatus \"{$this->status->label()}\" tidak dapat diubah menjadi \"{$to->label()}\".";
    }

    /**
     * The public URLs of the photos the customer attached.
     *
     * @return array<int, string>
     */
    public function photoUrls(): array
    {
        return array_values(array_map(
            fn (string $path): string => Storage::disk(self::PHOTO_DISK)->url($path),
            $this->photo_paths ?? [],
        ));
    }

    /**
     * Requests still awaiting a decision or an action from the branch.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (ReturnStatus $status): string => $status->value,
            ReturnStatus::openStatuses(),
        ));
    }

    /**
     * Requests whose goods are back and whose money has not been settled.
     *
     * The queue a central actor processing refunds works through: received,
     * refund-type, and not yet completed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function awaitingRefund(Builder $query): Builder
    {
        return $query
            ->where('status', ReturnStatus::Received->value)
            ->where('type', ReturnType::Refund->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('requested_at')->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
