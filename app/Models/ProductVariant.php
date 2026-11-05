<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Observers\ProductVariantObserver;
use App\Support\AttributeCombinations;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One sellable unit of a product (P1-E3-T4).
 *
 * Everything downstream points here rather than at the product: stock is held
 * per (warehouse, variant), and cart and order lines carry a variant id. A
 * `simple` product therefore still owns exactly one variant (ADR-020).
 *
 * The price, weight and dimension columns are nullable overrides: a null means
 * "whatever the product says", so a variant only stores what differs. Read
 * them through the `effective*` helpers, never directly.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $name
 * @property int|null $price
 * @property int|null $compare_at_price
 * @property int|null $cost_price
 * @property int|null $weight_gram
 * @property int|null $length_cm
 * @property int|null $width_cm
 * @property int|null $height_cm
 * @property string|null $image_path
 * @property bool $is_active
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Product $product
 * @property-read Collection<int, ProductAttributeValue> $attributeValues
 */
#[ObservedBy(ProductVariantObserver::class)]
#[Fillable([
    'product_id', 'sku', 'barcode', 'name',
    'price', 'compare_at_price', 'cost_price',
    'weight_gram', 'length_cm', 'width_cm', 'height_cm',
    'image_path', 'is_active', 'is_default',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'cost_price' => 'integer',
            'weight_gram' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The attribute values that define this variant, e.g. Merah and XL.
     *
     * @return BelongsToMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attribute_values',
        )->withTimestamps();
    }

    /**
     * The catalogue-wide price of this variant, before any branch override.
     *
     * A branch's own price takes precedence; `EffectivePriceResolver`
     * (P1-E3-T10) is what weighs the two.
     */
    public function effectivePrice(): int
    {
        return $this->price ?? $this->product->base_price;
    }

    public function effectiveCompareAtPrice(): ?int
    {
        return $this->compare_at_price ?? $this->product->compare_at_price;
    }

    public function effectiveCostPrice(): ?int
    {
        return $this->cost_price ?? $this->product->cost_price;
    }

    /**
     * The shipping weight of this variant, which a quote can never do without.
     */
    public function effectiveWeightGram(): int
    {
        return $this->weight_gram ?? $this->product->weight_gram;
    }

    /**
     * How this variant reads in a list, falling back to the product's name for
     * a `simple` product whose single variant carries no attributes.
     */
    public function displayName(): string
    {
        return $this->name ?? $this->product->name;
    }

    /**
     * Whether this variant may be deleted, and why not when it may not.
     *
     * One source for the controller's refusal and for the `can.delete` flag the
     * editor reads, so the button and the endpoint can never disagree — the
     * pattern `Branch::deletionBlockedReason()` set.
     *
     * P1-E4-T1 adds a reason here: a variant holding stock or carrying stock
     * movements must not leave either.
     *
     * The sibling count comes from `Product::variantCount()`, which reuses a
     * `variants_count` the caller already has — so asking every row of the
     * editor costs no query.
     */
    public function isDeletable(): bool
    {
        return $this->deletionBlockedReason() === null;
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->is_default) {
            return 'Varian utama tidak dapat dihapus. Tunjuk varian lain sebagai varian utama terlebih dahulu.';
        }

        if ($this->product->variantCount() <= 1) {
            return 'Produk harus punya minimal satu varian, karena stok dan pesanan selalu menunjuk varian.';
        }

        return null;
    }

    /**
     * A stable key for this variant's option combination, used to keep two
     * variants of one product from carrying the same options.
     *
     * Reads the already loaded `attributeValues` when they are there, because
     * `Model::preventLazyLoading()` is on.
     */
    public function combinationFingerprint(): string
    {
        $ids = $this->relationLoaded('attributeValues')
            ? $this->attributeValues->pluck('id')->all()
            : $this->attributeValues()->pluck('product_attribute_values.id')->all();

        return AttributeCombinations::fingerprint($ids);
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
