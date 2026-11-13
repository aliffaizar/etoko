<?php

namespace App\Models;

use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant on a stock transfer (P2-E3-T1).
 *
 * Each quantity belongs to one step and stays null until that step happens;
 * see the migration for why there are four of them.
 *
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_variant_id
 * @property int $quantity_requested
 * @property int|null $quantity_approved
 * @property int|null $quantity_shipped
 * @property int|null $quantity_received
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransfer $stockTransfer
 * @property-read ProductVariant $productVariant
 */
#[Fillable([
    'stock_transfer_id', 'product_variant_id',
    'quantity_requested', 'quantity_approved', 'quantity_shipped', 'quantity_received',
])]
class StockTransferItem extends Model
{
    /** @use HasFactory<StockTransferItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'quantity_shipped' => 'integer',
            'quantity_received' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /**
     * Read with trashed variants too: a line that already moved stock must
     * still name what it moved after the variant is retired.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    /**
     * Units that left the origin but did not arrive (P2-E3-T4).
     *
     * Zero until the line has been received. Not stored: it is two stored
     * columns subtracted, and a third copy is one more thing that could
     * disagree with them.
     */
    public function discrepancy(): int
    {
        if ($this->quantity_shipped === null || $this->quantity_received === null) {
            return 0;
        }

        return $this->quantity_shipped - $this->quantity_received;
    }
}
