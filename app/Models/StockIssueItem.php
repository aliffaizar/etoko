<?php

namespace App\Models;

use Database\Factories\StockIssueItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a goods-out document (P1-E4-T6).
 *
 * `quantity` is positive here too, as on a goods-in line: the direction
 * belongs to the document, and `StockLedger` is what makes the movement
 * negative.
 *
 * @property int $id
 * @property int $stock_issue_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property int|null $unit_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockIssue $stockIssue
 * @property-read ProductVariant $productVariant
 */
#[Fillable(['stock_issue_id', 'product_variant_id', 'quantity', 'unit_cost'])]
class StockIssueItem extends Model
{
    /** @use HasFactory<StockIssueItemFactory> */
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
     * @return BelongsTo<StockIssue, $this>
     */
    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
