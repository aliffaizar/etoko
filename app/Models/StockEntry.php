<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\StockDocumentStatus;
use Database\Factories\StockEntryFactory;
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
 * Goods arriving at a warehouse (P1-E4-T5).
 *
 * A document rather than a movement: typed as a draft, checked, and only then
 * posted — which is the moment `PostStockEntry` has `StockLedger` move the
 * figures. A posted document is never edited and never deleted, because its
 * ledger rows explain the figure it produced; a mistake is undone by posting
 * the opposite goods-out note.
 *
 * @property int $id
 * @property string $code
 * @property int $branch_id
 * @property int $warehouse_id
 * @property string|null $supplier_name
 * @property string|null $reference_no
 * @property Carbon $entry_date
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
 * @property-read Collection<int, StockEntryItem> $items
 */
#[Fillable([
    'code', 'branch_id', 'warehouse_id', 'supplier_name', 'reference_no',
    'entry_date', 'status', 'notes', 'created_by', 'posted_by', 'posted_at',
])]
class StockEntry extends Model
{
    /** @use HasFactory<StockEntryFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'status' => StockDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockEntryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
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
     * Reuses an `items_sum_quantity` the caller already loaded with
     * `withSum('items', 'quantity')`, so drawing a listing costs no query per
     * row — the pattern `Product::variantCount()` set.
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
     * One source for the controller's refusal and for the `can.post` flag the
     * screen reads, so the button and the endpoint cannot disagree — the
     * pattern `Branch::deletionBlockedReason()` set.
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
        return $query->orderByDesc('entry_date')->orderByDesc('id');
    }
}
