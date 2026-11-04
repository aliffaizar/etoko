<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\CreateProduct;
use App\Actions\Product\UpdateProduct;
use App\Enums\ProductPublicationState;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexProductRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Requests\Admin\UpdateProductStatusRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\CategoryTree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product management (P1-E3-T5).
 *
 * This screen owns a product's own data: its category, prices, weight and
 * dimensions, SEO fields, publication state and the featured flag. Three
 * things it deliberately does not own, each with its own task: the variant
 * combinations (P1-E3-T6), the photo gallery (P1-E3-T7), and what each branch
 * does with the product — availability (P1-E3-T9) and price (P1-E3-T10).
 *
 * The SKU and barcode on the form belong to the product's default variant,
 * which every product has (ADR-020).
 *
 * Products are managed centrally (BR-1): `ProductPolicy` demands a central
 * actor for every write, while a branch admin, warehouse staff and a cashier
 * hold `products.view` and read the same listing.
 */
class ProductController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexProductRequest $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->filters();
        $actor = $request->actingUser();

        return Inertia::render('admin/products/index', [
            'products' => $this->paginatedProducts($actor, $filters),
            'filters' => $filters,
            'options' => $this->filterOptions(),
            'can' => ['create' => $actor->can('create', Product::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('admin/products/create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $product = $createProduct->handle($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk {$product->name} berhasil dibuat."]);

        return to_route('admin.products.edit', $product);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        /** @var User $actor */
        $actor = request()->user();

        $product->load(['category:id,name', 'defaultVariant']);
        $product->loadCount('variants');

        return Inertia::render('admin/products/edit', [
            'product' => $this->productDetail($product),
            'options' => $this->formOptions(),
            'can' => [
                'update' => $actor->can('update', $product),
                'delete' => $actor->can('delete', $product),
            ],
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct): RedirectResponse
    {
        $this->authorize('update', $product);

        $updateProduct->handle($product, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan produk berhasil disimpan.']);

        return to_route('admin.products.edit', $product);
    }

    /**
     * The active and featured toggles on the listing.
     */
    public function updateStatus(UpdateProductStatusRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $flags = $request->flags();

        $product->update($flags);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->statusMessageFor($product, $flags),
        ]);

        return back();
    }

    /**
     * Soft deletes the product, keeping its variants and order history intact.
     *
     * Nothing blocks it: an order line snapshots the name and price it was
     * bought at (ADR-007), so a sold product can leave the catalogue without
     * taking its history with it.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk {$product->name} dihapus."]);

        return to_route('admin.products.index');
    }

    /**
     * @param  array{search: string, category: string, state: string, type: string, featured: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedProducts(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->listQuery($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->defaultVariant?->sku,
                'category_name' => $product->category->name,
                'type' => $product->type->value,
                'type_label' => $product->type->label(),
                'base_price' => $product->base_price,
                'compare_at_price' => $product->compare_at_price,
                'weight_gram' => $product->weight_gram,
                'is_active' => $product->is_active,
                'is_featured' => $product->is_featured,
                'state' => $product->publicationState()->value,
                'state_label' => $product->publicationState()->label(),
                'published_at' => $product->published_at?->toIso8601String(),
                'variant_count' => $product->variantCount(),
                'sold_count' => $product->sold_count,
                'can' => [
                    'update' => $actor->can('update', $product),
                    'delete' => $actor->can('delete', $product),
                ],
            ]);
    }

    /**
     * @param  array{search: string, category: string, state: string, type: string, featured: string, sort: string, direction: string}  $filters
     * @return Builder<Product>
     */
    private function listQuery(array $filters): Builder
    {
        return Product::query()
            ->with(['category:id,name', 'defaultVariant:id,product_id,sku'])
            ->withCount(['variants'])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('slug', 'like', "%{$filters['search']}%")
                    ->orWhereHas('variants', fn (Builder $variants) => $variants
                        ->where('sku', 'like', "%{$filters['search']}%")
                        ->orWhere('barcode', 'like', "%{$filters['search']}%")),
            ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->whereIn('category_id', $this->categoryBranchIds((int) $filters['category'])))
            ->when($filters['state'] !== '', fn (Builder $query) => $query
                ->withPublicationState(ProductPublicationState::from($filters['state'])))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']))
            ->when($filters['featured'] !== '', fn (Builder $query) => $query
                ->where('is_featured', $filters['featured'] === 'yes'))
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * The chosen category and everything below it, so filtering by a parent
     * shows the products filed under its subcategories too.
     *
     * @return array<int, int>
     */
    private function categoryBranchIds(int $categoryId): array
    {
        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return [$categoryId];
        }

        return [$category->getKey(), ...$category->descendantIds()];
    }

    /**
     * @return array<string, mixed>
     */
    private function productDetail(Product $product): array
    {
        $defaultVariant = $product->defaultVariant;

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'category_name' => $product->category->name,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type->value,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'base_price' => $product->base_price,
            'compare_at_price' => $product->compare_at_price,
            'cost_price' => $product->cost_price,
            'weight_gram' => $product->weight_gram,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            // The datetime-local input wants `YYYY-MM-DDTHH:MM` in the app's
            // own timezone, not an ISO string with an offset.
            'published_at' => $product->published_at?->format('Y-m-d\TH:i'),
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'sku' => $defaultVariant instanceof ProductVariant ? $defaultVariant->sku : '',
            'barcode' => $defaultVariant instanceof ProductVariant ? $defaultVariant->barcode : null,
            'state' => $product->publicationState()->value,
            'state_label' => $product->publicationState()->label(),
            'variant_count' => $product->variantCount(),
            'sold_count' => $product->sold_count,
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{categories: array<int, array{value: int, label: string, depth: int}>, types: array<int, array{value: string, label: string}>, states: array<int, array{value: string, label: string}>}
     */
    private function filterOptions(): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'types' => ProductType::options(),
            'states' => ProductPublicationState::options(),
        ];
    }

    /**
     * @return array{categories: array<int, array{value: int, label: string, depth: int}>, types: array<int, array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'types' => ProductType::options(),
        ];
    }

    /**
     * Every category, in tree order, indented by depth.
     *
     * Inactive categories are offered too: filing a product under one is how a
     * catalogue is prepared before it goes live, and the category's own state
     * is what decides visibility.
     *
     * @return array<int, array{value: int, label: string, depth: int}>
     */
    private function categoryOptions(): array
    {
        return array_map(
            fn (array $row): array => [
                'value' => $row['category']->id,
                'label' => $row['category']->name,
                'depth' => $row['depth'],
            ],
            CategoryTree::of(Category::query()->get())->flatten(),
        );
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function statusMessageFor(Product $product, array $flags): string
    {
        if (array_key_exists('is_featured', $flags)) {
            return $flags['is_featured']
                ? "Produk {$product->name} ditandai sebagai unggulan."
                : "Produk {$product->name} tidak lagi ditandai unggulan.";
        }

        return ($flags['is_active'] ?? false)
            ? "Produk {$product->name} diaktifkan."
            : "Produk {$product->name} dinonaktifkan.";
    }
}
