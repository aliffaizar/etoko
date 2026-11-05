<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Branch\SetBranchProductAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexBranchProductRequest;
use App\Http\Requests\Admin\UpdateBranchProductAvailabilityRequest;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CategoryTree;
use App\Support\ProductListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which products one branch sells (P1-E3-T9).
 *
 * The catalogue is central (BR-1); what a branch decides is whether it offers
 * a product at all. Only the exceptions are stored, so this screen reads the
 * whole product listing and left-joins the branch's own rows onto it — a
 * product with no row is available, and the table stays the size of the
 * exceptions rather than branches × products.
 *
 * Switching products on and off is central work, which is why the write
 * demands a central actor through `BranchPolicy::manageAvailability` while a
 * branch admin reads the same screen for their own branch.
 *
 * Nested under the branch, so a branch admin reaching another branch's
 * availability is refused by the policy rather than narrowed silently.
 */
class BranchProductController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexBranchProductRequest $request, Branch $branch): Response
    {
        $this->authorize('viewCatalog', $branch);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/branches/products', [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'products' => $this->paginatedProducts($branch, $filters),
            'filters' => $filters,
            'options' => ['categories' => $this->categoryOptions()],
            'summary' => $this->summaryFor($branch),
            'can' => ['manage' => $actor->can('manageAvailability', $branch)],
            'limits' => ['max_products' => UpdateBranchProductAvailabilityRequest::MAX_PRODUCTS],
        ]);
    }

    /**
     * Applies one availability decision to one or many products.
     *
     * The row toggle and the bulk action are the same operation over a
     * different number of products, so they share this endpoint.
     */
    public function update(
        UpdateBranchProductAvailabilityRequest $request,
        Branch $branch,
        SetBranchProductAvailability $setAvailability,
    ): RedirectResponse {
        $this->authorize('manageAvailability', $branch);

        $changed = $setAvailability->handle(
            $branch,
            $request->productIds(),
            $request->boolean('is_available'),
            $request->notes(),
        );

        Inertia::flash('toast', [
            'type' => $changed === 0 ? 'info' : 'success',
            'message' => $this->resultMessage($changed, $request->boolean('is_available')),
        ]);

        return back();
    }

    private function resultMessage(int $changed, bool $isAvailable): string
    {
        if ($changed === 0) {
            return 'Tidak ada perubahan: produk tersebut sudah dalam keadaan itu.';
        }

        return $isAvailable
            ? "{$changed} produk kini tersedia di cabang ini."
            : "{$changed} produk kini tidak dijual di cabang ini.";
    }

    /**
     * @param  array{search: string, category: string, availability: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedProducts(Branch $branch, array $filters): LengthAwarePaginator
    {
        return $this->listQuery($branch, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(function (Product $product) use ($branch): array {
                $setting = $this->settingOf($product, $branch);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->defaultVariant?->sku,
                    'category_name' => $product->category->name,
                    'base_price' => $product->base_price,
                    'is_active' => $product->is_active,
                    'is_published' => $product->isPublished(),
                    'is_available' => BranchProduct::availabilityFor($setting),
                    // True only when the branch has actually said something,
                    // which is what the "mengikuti default" hint reads.
                    'has_setting' => $setting instanceof BranchProduct,
                    'notes' => $setting?->notes,
                ];
            });
    }

    /**
     * The branch's row for a product, from the relation loaded with the page.
     */
    private function settingOf(Product $product, Branch $branch): ?BranchProduct
    {
        return $product->branchSettings
            ->firstWhere('branch_id', $branch->getKey());
    }

    /**
     * @param  array{search: string, category: string, availability: string, sort: string, direction: string}  $filters
     * @return Builder<Product>
     */
    private function listQuery(Branch $branch, array $filters): Builder
    {
        return Product::query()
            ->with([
                'category:id,name',
                'defaultVariant:id,product_id,sku',
                'branchSettings' => fn ($settings) => $settings->where('branch_id', $branch->getKey()),
            ])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('slug', 'like', "%{$filters['search']}%")
                    ->orWhereHas('variants', fn (Builder $variants) => $variants
                        ->where('sku', 'like', "%{$filters['search']}%")
                        ->orWhere('barcode', 'like', "%{$filters['search']}%")),
            ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->whereIn('category_id', ProductListQuery::categoryBranchIds((int) $filters['category'])))
            ->when($filters['availability'] === 'unavailable', fn (Builder $query) => $query
                ->whereHas('branchSettings', fn (Builder $settings) => $settings
                    ->where('branch_id', $branch->getKey())
                    ->where('is_available', false)))
            // "Available" is the default, so it is every product *except* the
            // ones this branch has switched off — not the ones with a row.
            ->when($filters['availability'] === 'available', fn (Builder $query) => $query
                ->whereDoesntHave('branchSettings', fn (Builder $settings) => $settings
                    ->where('branch_id', $branch->getKey())
                    ->where('is_available', false)))
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * How much of the catalogue this branch has switched off.
     *
     * @return array{total: int, unavailable: int, available: int}
     */
    private function summaryFor(Branch $branch): array
    {
        $total = Product::query()->count();
        $unavailable = BranchProduct::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->unavailable()
            ->count();

        return [
            'total' => $total,
            'unavailable' => $unavailable,
            'available' => $total - $unavailable,
        ];
    }

    /**
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
}
