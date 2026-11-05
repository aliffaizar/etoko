<?php

namespace App\Models;

use Database\Factories\StockOpnameItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant being counted in a stock opname (P1-E4-T7).
 *
 * `system_quantity` is a snapshot of what the system believed when the line
 * was added, kept so the variance stays readable afterwards — "we thought 12,
 * we found 9" — and so completion posts the variance rather than the counted
 * figure.
 *
 * `counted_quantity` stays null until somebody actually counts the line, which
 * is what separates "not counted yet" from "counted zero". Those are very
 * different findings and the table must not be able to confuse them.
 *
 * @property int $id
 * @property int $stock_opname_id
 * @property int $product_variant_id
 * @property int $system_quantity
 * @property int|null $counted_quantity
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockOpname $stockOpname
 * @property-read ProductVariant $productVariant
 */
#[Fillable(['stock_opname_id', 'product_variant_id', 'system_quantity', 'counted_quantity', 'notes'])]
class StockOpnameItem extends Model
{
    /** @use HasFactory<StockOpnameItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockOpname, $this>
     */
    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /**
     * How far the count differs from what the system believed, or null when
     * the line has not been counted.
     *
     * Computed rather than stored: a stored copy is one more thing that can
     * disagree with the two columns it came from.
     */
    public function difference(): ?int
    {
        if ($this->counted_quantity === null) {
            return null;
        }

        return $this->counted_quantity - $this->system_quantity;
    }
}
