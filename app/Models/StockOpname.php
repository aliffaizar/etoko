<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use App\Enums\StockOpnameStatus;
use Database\Factories\StockOpnameFactory;
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
 * A physical stock count (P1-E4-T7).
 *
 * The session lists the variants being counted, each carrying a snapshot of
 * what the system believed at the time the line was added. Completing it posts
 * the **variance** of every counted line — not the counted figure — so stock
 * that moved legitimately while the shelves were being walked survives the
 * count. See `CompleteStockOpname`.
 *
 * @property int $id
 * @property string $code
 * @property int $branch_id
 * @property int $warehouse_id
 * @property StockOpnameStatus $status
 * @property Carbon $counted_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Warehouse $warehouse
 * @property-read User|null $createdBy
 * @property-read User|null $approvedBy
 * @property-read Collection<int, StockOpnameItem> $items
 */
#[Fillable([
    'code', 'branch_id', 'warehouse_id', 'status', 'counted_at',
    'notes', 'created_by', 'approved_by', 'completed_at',
])]
class StockOpname extends Model
{
    /** @use HasFactory<StockOpnameFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockOpnameStatus::class,
            'counted_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockOpnameItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
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
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Why this count may not be completed, or null when it may be.
     *
     * An uncounted line is not an error: a session may be completed with lines
     * left blank, and those lines simply post nothing. What is refused is a
     * session where *nothing at all* was counted, which would post nothing
     * while claiming a count happened.
     */
    public function completionBlockedReason(): ?string
    {
        if ($this->status === StockOpnameStatus::Completed) {
            return 'Sesi opname ini sudah selesai, jadi tidak bisa diselesaikan ulang.';
        }

        if ($this->status === StockOpnameStatus::Cancelled) {
            return 'Sesi opname yang sudah dibatalkan tidak bisa diselesaikan. Buat sesi baru.';
        }

        if ($this->items()->whereNotNull('counted_quantity')->count() === 0) {
            return 'Isi hasil hitung minimal satu baris sebelum menyelesaikan sesi opname.';
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
        return $query->orderByDesc('counted_at')->orderByDesc('id');
    }
}
