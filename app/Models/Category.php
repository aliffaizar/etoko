<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Observers\CategoryObserver;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A node of the product category tree (P1-E3-T1).
 *
 * Categories are managed centrally and shared by every branch (BR-1): a branch
 * decides which products it stocks (`branch_products`, P1-E3-T9), never which
 * categories exist.
 *
 * The tree is at most `MAX_DEPTH` levels deep, which is what makes the plain
 * `parent_id` column enough: an ancestor walk and a subtree sweep both finish
 * in a bounded number of queries, so no nested-set or path column is needed.
 * The limit itself is enforced in the form requests, because it is a rule about
 * the submitted parent rather than about the row.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image_path
 * @property string|null $icon
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 */
#[ObservedBy(CategoryObserver::class)]
#[Fillable([
    'parent_id', 'name', 'slug', 'description', 'image_path', 'icon', 'is_active', 'sort_order',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * How many levels the tree may span, counting the roots as level 1.
     */
    public const MAX_DEPTH = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * This category's own level, 1 for a root.
     *
     * Walks up by id rather than through the `parent` relation, so it is an
     * explicit query every time instead of a lazy load, and it still reads
     * ancestors that have been soft deleted.
     */
    public function depth(): int
    {
        $depth = 1;
        $parentId = $this->parent_id;

        // The ceiling is the loop's own guard: a cycle would otherwise spin
        // forever, and a cycle can only exist if validation was bypassed.
        while ($parentId !== null && $depth <= self::MAX_DEPTH) {
            $parentId = static::withTrashed()->whereKey($parentId)->value('parent_id');
            $depth++;
        }

        return $depth;
    }

    /**
     * How many levels this category's subtree spans, 1 when it has no children.
     *
     * Read before re-parenting: a subtree two levels tall cannot be moved under
     * a category that already sits at level 2.
     */
    public function subtreeHeight(): int
    {
        $height = 1;
        $ids = [$this->getKey()];

        while ($ids !== [] && $height <= self::MAX_DEPTH) {
            $ids = static::query()->whereIn('parent_id', $ids)->pluck('id')->all();

            if ($ids !== []) {
                $height++;
            }
        }

        return $height;
    }

    /**
     * Ids of every category below this one.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $found = [];
        $frontier = [$this->getKey()];
        $levels = 0;

        while ($frontier !== [] && $levels < self::MAX_DEPTH) {
            /** @var array<int, int> $frontier */
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $found = [...$found, ...$frontier];
            $levels++;
        }

        return $found;
    }

    /**
     * The number of subcategories directly below this one.
     *
     * Reuses the `children_count` of a `withCount(['children'])` listing when
     * it is there, so drawing the tree does not cost one query per row.
     */
    public function childCount(): int
    {
        $counted = $this->getAttribute('children_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return $this->relationLoaded('children') ? $this->children->count() : $this->children()->count();
    }

    /**
     * The number of products filed directly under this category.
     *
     * Reuses the `products_count` of a `withCount(['products'])` listing when
     * it is there, the same way `childCount()` does.
     */
    public function productCount(): int
    {
        $counted = $this->getAttribute('products_count');

        if ($counted !== null) {
            return (int) $counted;
        }

        return $this->relationLoaded('products') ? $this->products->count() : $this->products()->count();
    }

    /**
     * Why this category may not be deleted, or null when it may be.
     *
     * One predicate for the controller's guard and for the capability flag the
     * category screens read, the way `Branch::deletionBlockedReason()` does, so
     * the interface never offers a deletion the server is going to refuse.
     */
    public function deletionBlockedReason(): ?string
    {
        $childCount = $this->childCount();

        if ($childCount > 0) {
            return "Kategori ini masih memiliki {$childCount} subkategori. Hapus atau pindahkan subkategori tersebut terlebih dahulu.";
        }

        $productCount = $this->productCount();

        if ($productCount > 0) {
            return "Kategori ini masih dipakai {$productCount} produk. Pindahkan produk tersebut ke kategori lain terlebih dahulu.";
        }

        return null;
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockedReason() === null;
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
    protected function roots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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
