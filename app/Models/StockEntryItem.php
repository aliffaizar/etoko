<?php

namespace App\Models;

use Database\Factories\StockEntryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a goods-in document (P1-E4-T5).
 *
 * `quantity` is always positive: the direction belongs to the document, and a
 * negative line on a goods-in note would be a goods-out note hiding inside
 * one. `StockLedger` is what gives the movement its sign.
 *
 * Not `Auditable`: lines only change while the document is a draft, and the
 * document itself is audited — a draft's keystrokes are noise in the log,
 * while the posting that matters is recorded on the parent and in the ledger.
 *
 * @property int $id
 * @property int $stock_entry_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property int|null $unit_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockEntry $stockEntry
 * @property-read ProductVariant $productVariant
 */
#[Fillable(['stock_entry_id', 'product_variant_id', 'quantity', 'unit_cost'])]
class StockEntryItem extends Model
{
    /** @use HasFactory<StockEntryItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockEntry, $this>
     */
    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
