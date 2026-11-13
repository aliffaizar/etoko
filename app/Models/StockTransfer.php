<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\StockTransferStatus;
use App\Scopes\StockTransferBranchScope;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Stock moving from one branch's warehouse to another's (P2-E3).
 *
 * The destination asks, the origin (or central) agrees, the origin ships —
 * the moment its stock leaves as `transfer_out` — and the destination
 * receives, which is when its stock arrives as `transfer_in`. In between the
 * goods are on the road and counted in neither branch (docs/03-data-model.md).
 *
 * It has two branches, so it does not use `BelongsToBranch`:
 * `StockTransferBranchScope` lets either end see it and nobody else (BR-3).
 *
 * @property int $id
 * @property string $code
 * @property int $from_branch_id
 * @property int $from_warehouse_id
 * @property int $to_branch_id
 * @property int $to_warehouse_id
 * @property StockTransferStatus $status
 * @property string|null $notes
 * @property int|null $requested_by
 * @property Carbon|null $requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $shipped_by
 * @property Carbon|null $shipped_at
 * @property int|null $received_by
 * @property Carbon|null $received_at
 * @property string|null $receipt_notes
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $fromBranch
 * @property-read Warehouse $fromWarehouse
 * @property-read Branch $toBranch
 * @property-read Warehouse $toWarehouse
 * @property-read User|null $requestedBy
 * @property-read User|null $approvedBy
 * @property-read User|null $rejectedBy
 * @property-read User|null $shippedBy
 * @property-read User|null $receivedBy
 * @property-read User|null $cancelledBy
 * @property-read Collection<int, StockTransferItem> $items
 */
#[Fillable([
    'code', 'from_branch_id', 'from_warehouse_id', 'to_branch_id', 'to_warehouse_id',
    'status', 'notes',
    'requested_by', 'requested_at', 'approved_by', 'approved_at',
    'rejected_by', 'rejected_at', 'rejection_reason',
    'shipped_by', 'shipped_at', 'received_by', 'received_at', 'receipt_notes',
    'cancelled_by', 'cancelled_at',
])]
#[ScopedBy(StockTransferBranchScope::class)]
class StockTransfer extends Model
{
    /** @use HasFactory<StockTransferFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Query transfers regardless of which branch is asking.
     *
     * For actions that lock the row they were handed (the caller already
     * resolved it through the scope), jobs and listeners. State the reason at
     * the call site.
     *
     * @return Builder<static>
     */
    public static function acrossBranches(): Builder
    {
        return static::query()->withoutGlobalScope(StockTransferBranchScope::class);
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /**
     * Read across branches: the warehouse at the other end belongs to a
     * branch the viewer may not otherwise see, and the transfer is what
     * entitles them to its name.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isFromBranch(?int $branchId): bool
    {
        return $branchId !== null && $branchId === $this->from_branch_id;
    }

    public function isToBranch(?int $branchId): bool
    {
        return $branchId !== null && $branchId === $this->to_branch_id;
    }

    /**
     * Why the origin may not approve this transfer now, or null when it may.
     *
     * One source for the action's refusal and the screen's `can.approve`, the
     * pattern `StockIssue::postingBlockedReason()` set. Whether the origin
     * holds enough is **not** asked here: approving moves nothing, and the
     * shelf is checked under lock when the goods actually leave.
     */
    public function approvalBlockedReason(): ?string
    {
        if ($this->status !== StockTransferStatus::Requested) {
            return "Transfer berstatus \"{$this->status->label()}\" tidak dapat disetujui lagi.";
        }

        return null;
    }

    public function rejectionBlockedReason(): ?string
    {
        if (! $this->status->isStoppable()) {
            return $this->status->hasShipped()
                ? 'Barang sudah dikirim, jadi transfer ini tidak dapat ditolak. Selesaikan dengan penerimaan.'
                : "Transfer berstatus \"{$this->status->label()}\" tidak dapat ditolak.";
        }

        return null;
    }

    public function shippingBlockedReason(): ?string
    {
        if ($this->status !== StockTransferStatus::Approved) {
            return $this->status === StockTransferStatus::Requested
                ? 'Transfer harus disetujui dulu sebelum barang dikirim.'
                : "Transfer berstatus \"{$this->status->label()}\" tidak dapat dikirim.";
        }

        return null;
    }

    public function receivingBlockedReason(): ?string
    {
        if ($this->status !== StockTransferStatus::InTransit) {
            return $this->status->hasShipped()
                ? 'Transfer ini sudah diterima.'
                : 'Barang belum dikirim cabang asal, jadi belum ada yang bisa diterima.';
        }

        return null;
    }

    public function cancellationBlockedReason(): ?string
    {
        if (! $this->status->isStoppable()) {
            return $this->status->hasShipped()
                ? 'Barang sudah dikirim, jadi transfer ini tidak dapat dibatalkan. Selesaikan dengan penerimaan.'
                : "Transfer berstatus \"{$this->status->label()}\" tidak dapat dibatalkan.";
        }

        return null;
    }

    /**
     * Units asked for, across every line.
     *
     * Reuses an `items_sum_quantity_requested` the caller already loaded, as
     * `StockIssue::totalQuantity()` does.
     */
    public function totalRequested(): int
    {
        $summed = $this->getAttribute('items_sum_quantity_requested');

        if ($summed !== null) {
            return (int) $summed;
        }

        return $this->relationLoaded('items')
            ? (int) $this->items->sum('quantity_requested')
            : (int) $this->items()->sum('quantity_requested');
    }

    /**
     * Units that left the origin but never arrived, across every line.
     */
    public function totalDiscrepancy(): int
    {
        return (int) $this->items->sum(fn (StockTransferItem $item): int => $item->discrepancy());
    }

    /**
     * Only the transfers the given branch is a party to, from either end.
     *
     * The explicit form of `StockTransferBranchScope`, for central screens
     * that look at one branch's transfers without being restricted to it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function visibleToBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(fn (Builder $either) => $either
            ->where('from_branch_id', $branchId)
            ->orWhere('to_branch_id', $branchId));
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
}
