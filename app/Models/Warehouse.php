<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\BelongsToBranch;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Collection<int, Stock> $stocks
 */
#[Fillable(['branch_id', 'code', 'name', 'address', 'is_default', 'is_active'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use Auditable, BelongsToBranch, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * What this warehouse holds, one row per variant it has ever held
     * (P1-E4-T1).
     *
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Every recorded change to this warehouse's stock (P1-E4-T1).
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockedReason() === null;
    }

    /**
     * Why this warehouse may not be deleted, or null when it may be.
     *
     * One source for the controller's refusal and for the `can_delete` flag
     * the branch screen reads, so the button and the endpoint can never
     * disagree — the pattern `Branch::deletionBlockedReason()` set.
     *
     * A warehouse holding stock must not leave: its rows would cascade away
     * and the goods on its shelves would vanish from the system while staying
     * on the shelves. Nor may one that carries movements, because the ledger
     * rows that explain every figure would cascade with it (P1-E4-T1).
     */
    public function deletionBlockedReason(): ?string
    {
        if ($this->is_default) {
            return 'Gudang utama tidak dapat dihapus. Tunjuk gudang lain sebagai gudang utama terlebih dahulu.';
        }

        $onHand = $this->onHandQuantity();

        if ($onHand !== 0) {
            return "Gudang ini masih memegang {$onHand} unit stok. Pindahkan atau keluarkan stoknya terlebih dahulu.";
        }

        if ($this->stockMovements()->exists()) {
            return 'Gudang ini sudah punya riwayat pergerakan stok, jadi tidak dapat dihapus. Nonaktifkan gudang bila tidak dipakai lagi.';
        }

        return null;
    }

    /**
     * Total units on this warehouse's shelves, across every variant.
     *
     * Reuses a `stocks_sum_quantity` the caller already loaded with
     * `withSum('stocks', 'quantity')`, so asking every warehouse of a branch
     * costs no extra query — the pattern `Product::variantCount()` set.
     */
    public function onHandQuantity(): int
    {
        $summed = $this->getAttribute('stocks_sum_quantity');

        if ($summed !== null) {
            return (int) $summed;
        }

        return (int) $this->stocks()->sum('quantity');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function default(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
