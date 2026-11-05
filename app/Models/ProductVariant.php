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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property-read Collection<int, BranchProductPrice> $branchPrices
 * @property-read Collection<int, Stock> $stocks
 * @property-read Collection<int, StockMovement> $stockMovements
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
     * The branch-specific prices of this variant (P1-E3-T10).
     *
     * A branch with no row here charges what the catalogue charges;
     * `EffectivePriceResolver` is what weighs the two (BR-8).
     *
     * @return HasMany<BranchProductPrice, $this>
     */
    public function branchPrices(): HasMany
    {
        return $this->hasMany(BranchProductPrice::class);
    }

    /**
     * The per-warehouse stock rows of this variant (P1-E4-T1).
     *
     * A warehouse with no row here holds none; `Stock::quantityFor()` is what
     * reads a missing row as zero.
     *
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Every recorded change to this variant's stock (P1-E4-T1).
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
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
     * A variant that holds stock or carries stock movements may not leave
     * either: deleting it would make the warehouse's figure unexplainable and
     * strand the ledger rows that explain it (P1-E4-T1).
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

        // Asked across every branch, not through the relation: product writes
        // are central (BR-1), but stock in *any* branch is still stock, and a
        // branch-scoped read would miss it.
        $holdsStock = Stock::acrossBranches()
            ->where('product_variant_id', $this->getKey())
            ->where(fn (Builder $query) => $query
                ->where('quantity', '!=', 0)
                ->orWhere('reserved_quantity', '!=', 0))
            ->exists();

        if ($holdsStock) {
            return 'Varian ini masih memegang stok di gudang. Keluarkan atau pindahkan stoknya terlebih dahulu.';
        }

        if (StockMovement::acrossBranches()->where('product_variant_id', $this->getKey())->exists()) {
            return 'Varian ini sudah punya riwayat pergerakan stok, jadi tidak dapat dihapus. Nonaktifkan varian bila tidak dijual lagi.';
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
