<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ProductAttributeType;
use App\Observers\ProductAttributeObserver;
use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An axis a product can vary along, such as Warna or Ukuran (P1-E3-T4).
 *
 * Attributes are shared by the whole catalogue rather than defined per product,
 * so "Merah" means the same thing everywhere and the storefront can offer one
 * colour filter across categories. The variant editor (P1-E3-T6) is what
 * combines them into variants.
 *
 * That sharing is also why an attribute in use cannot simply be deleted: its
 * values cascade, which would empty the pivot and leave variants of unrelated
 * products indistinguishable from one another.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ProductAttributeType $type
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ProductAttributeValue> $values
 */
#[ObservedBy(ProductAttributeObserver::class)]
#[Fillable(['name', 'slug', 'type', 'sort_order'])]
class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductAttributeType::class,
        ];
    }

    /**
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * How many variants across the catalogue are defined by one of this
     * attribute's values.
     *
     * Reuses a `variant_usage_count` from an aggregated listing when it is
     * there, so drawing the list costs one query rather than one per row.
     */
    public function variantUsageCount(): int
    {
        $counted = $this->getAttribute('variant_usage_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return ProductAttributeValue::query()
            ->where('product_attribute_id', $this->getKey())
            ->join(
                'product_variant_attribute_values',
                'product_attribute_values.id',
                '=',
                'product_variant_attribute_values.product_attribute_value_id',
            )
            ->count();
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockedReason() === null;
    }

    /**
     * Why this attribute may not be deleted, or null when it may.
     *
     * One source for the controller's refusal and for the `can.delete` flag the
     * UI reads, so the button and the endpoint can never disagree — the same
     * pattern `Branch::deletionBlockedReason()` set.
     */
    public function deletionBlockedReason(): ?string
    {
        $inUse = $this->variantUsageCount();

        if ($inUse > 0) {
            return "Atribut ini masih dipakai oleh {$inUse} varian produk. Hapus atau ubah varian tersebut lebih dahulu.";
        }

        return null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
