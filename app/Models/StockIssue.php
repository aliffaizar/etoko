<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use Database\Factories\StockIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Goods leaving a warehouse without being sold (P1-E4-T6).
 *
 * The mirror of `StockEntry`, with one addition that earns it its own table:
 * it has to say **why**. Shrinkage that cannot be counted by cause is
 * shrinkage nobody can do anything about.
 *
 * Stock sold does not come through here: it leaves through an order as a
 * `sale_out` movement (P1-E7).
 *
 * @property int $id
 * @property string $code
 * @property int $branch_id
 * @property int $warehouse_id
 * @property StockIssueReason $reason
 * @property string|null $reference_no
 * @property Carbon $issue_date
 * @property StockDocumentStatus $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Warehouse $warehouse
 * @property-read User|null $createdBy
 * @property-read User|null $postedBy
 * @property-read Collection<int, StockIssueItem> $items
 */
#[Fillable([
    'code', 'branch_id', 'warehouse_id', 'reason', 'reference_no',
    'issue_date', 'status', 'notes', 'created_by', 'posted_by', 'posted_at',
])]
class StockIssue extends Model
{
    /** @use HasFactory<StockIssueFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => StockIssueReason::class,
            'issue_date' => 'date',
            'status' => StockDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockIssueItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Units on the note, across every line.
     *
     * Reuses an `items_sum_quantity` the caller already loaded, as
     * `StockEntry::totalQuantity()` does.
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

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Why this document may not be posted, or null when it may be.
     *
     * One source for the controller's refusal and for the screen's `can.post`
     * flag. Whether the warehouse actually holds enough is **not** decided
     * here: that answer changes between the render and the click, so it is
     * `StockLedger`'s to give while it holds the row lock (BR-6).
     */
    public function postingBlockedReason(): ?string
    {
        if ($this->status === StockDocumentStatus::Posted) {
            return 'Dokumen ini sudah diposting, jadi tidak bisa diposting ulang.';
        }

        if ($this->status === StockDocumentStatus::Cancelled) {
            return 'Dokumen yang sudah dibatalkan tidak bisa diposting. Buat dokumen baru.';
        }

        if ($this->items()->count() === 0) {
            return 'Tambahkan minimal satu baris barang sebelum memposting dokumen.';
        }

        return null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('issue_date')->orderByDesc('id');
    }
}
