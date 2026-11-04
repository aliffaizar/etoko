<?php

namespace App\Models;

use App\Enums\ProductAttributeType;
use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ProductAttributeType $type
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ProductAttributeValue> $values
 */
#[Fillable(['name', 'slug', 'type', 'sort_order'])]
class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
