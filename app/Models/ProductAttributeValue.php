<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One option of a product attribute, such as Merah or XL (P1-E3-T4).
 *
 * `color_hex` is only meaningful when the attribute is a
 * `ProductAttributeType::Color`, where the storefront draws a swatch instead
 * of a label.
 *
 * @property int $id
 * @property int $product_attribute_id
 * @property string $value
 * @property string|null $label
 * @property string|null $color_hex
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductAttribute $attribute
 */
#[Fillable(['product_attribute_id', 'value', 'label', 'color_hex', 'sort_order'])]
class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use Auditable, HasFactory;

    /**
     * @return BelongsTo<ProductAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    /**
     * @return BelongsToMany<ProductVariant, $this>
     */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attribute_values',
        )->withTimestamps();
    }

    /**
     * How this value reads to a buyer: the label when one was given, the raw
     * value otherwise.
     */
    public function displayLabel(): string
    {
        return $this->label ?? $this->value;
    }

    /**
     * How many variants across the catalogue this value defines.
     *
     * Reuses the `variants_count` of a `withCount(['variants'])` listing when
     * it is there.
     */
    public function variantCount(): int
    {
        $counted = $this->getAttribute('variants_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return $this->variants()->count();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('value');
    }
}
