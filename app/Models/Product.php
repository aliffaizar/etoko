<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ProductPublicationState;
use App\Enums\ProductType;
use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A product in the central catalogue (P1-E3-T3).
 *
 * Products are managed centrally and shared by every branch (BR-1). What a
 * branch decides for itself is whether it sells the product
 * (`branch_products`, P1-E3-T9) and at what price (`branch_product_prices`,
 * P1-E3-T10); the columns here are the catalogue-wide fallback.
 *
 * Every product owns at least one variant, including a `simple` one (ADR-020):
 * stock, cart lines and order lines all point at a variant, never at a product,
 * so a product without one could not be sold.
 *
 * Money is whole rupiah in integer columns (ADR-008, BR-10).
 *
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property ProductType $type
 * @property string|null $short_description
 * @property string|null $description
 * @property int $base_price
 * @property int|null $compare_at_price
 * @property int|null $cost_price
 * @property int $weight_gram
 * @property int|null $length_cm
 * @property int|null $width_cm
 * @property int|null $height_cm
 * @property bool $is_active
 * @property bool $is_featured
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string $rating_avg
 * @property int $rating_count
 * @property int $sold_count
 * @property int $view_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category $category
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, ProductImage> $images
 */
#[ObservedBy(ProductObserver::class)]
#[Fillable([
    'category_id', 'name', 'slug', 'type', 'short_description', 'description',
    'base_price', 'compare_at_price', 'cost_price',
    'weight_gram', 'length_cm', 'width_cm', 'height_cm',
    'is_active', 'is_featured', 'published_at', 'meta_title', 'meta_description',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'base_price' => 'integer',
            'compare_at_price' => 'integer',
            'cost_price' => 'integer',
            'weight_gram' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'rating_avg' => 'decimal:2',
        ];
    }

    /**
     * The denormalised counters are maintained by the epics that own them, so
     * they are never mass assigned and never part of a form.
     *
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return [...$this->auditExcludeDefaults(), 'rating_avg', 'rating_count', 'sold_count', 'view_count'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The variant a storefront or POS screen falls back to.
     *
     * @return HasOne<ProductVariant, $this>
     */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * Where the product stands towards buyers, read from `is_active` and
     * `published_at` together.
     */
    public function publicationState(): ProductPublicationState
    {
        if (! $this->is_active) {
            return ProductPublicationState::Inactive;
        }

        if ($this->published_at === null) {
            return ProductPublicationState::Draft;
        }

        return $this->published_at->isFuture()
            ? ProductPublicationState::Scheduled
            : ProductPublicationState::Published;
    }

    /**
     * Whether the product is visible to buyers right now.
     */
    public function isPublished(): bool
    {
        return $this->publicationState()->isVisibleToBuyers();
    }

    /**
     * The number of variants this product has.
     *
     * Reuses the `variants_count` of a `withCount(['variants'])` listing when
     * it is there, so drawing the product table does not cost one query per row.
     */
    public function variantCount(): int
    {
        $counted = $this->getAttribute('variants_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return $this->relationLoaded('variants') ? $this->variants->count() : $this->variants()->count();
    }

    /**
     * Visible in the storefront: active and published (BR-8 reads prices and
     * stock per branch on top of this).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Narrows to one publication state, which is the listing's filter.
     *
     * Mirrors `publicationState()` in SQL; the two must agree, and the product
     * listing tests hold them to it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withPublicationState(Builder $query, ProductPublicationState $state): Builder
    {
        return match ($state) {
            ProductPublicationState::Inactive => $query->where('is_active', false),
            ProductPublicationState::Draft => $query->where('is_active', true)->whereNull('published_at'),
            ProductPublicationState::Scheduled => $query->where('is_active', true)
                ->whereNotNull('published_at')
                ->where('published_at', '>', now()),
            ProductPublicationState::Published => $query->published(),
        };
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
    protected function featured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }
}
