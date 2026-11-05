<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Branch\SetBranchProductPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexBranchProductPriceRequest;
use App\Http\Requests\Admin\StoreBranchProductPriceRequest;
use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\EffectivePriceResolver;
use App\Support\CategoryTree;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What one branch charges (P1-E3-T10).
 *
 * Listed per variant, because that is the grain a price is charged at: a cart
 * line and an order line both carry a variant id, so a price that could not be
 * told apart per variant could not be charged.
 *
 * Every row shows the price actually in force, worked out by
 * `EffectivePriceResolver` and labelled with where it came from — so the
 * difference between "Rp 250.000 because this branch says so" and "Rp 250.000
 * because the catalogue says so" is visible without opening the row (BR-8).
 *
 * Setting prices is central work: `BranchPolicy::managePrices` demands a
 * central actor, while a branch admin holding `branch_prices.view` reads the
 * same screen for their own branch.
 */
class BranchProductPriceController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexBranchProductPriceRequest $request, Branch $branch): Response
    {
        $this->authorize('viewPrices', $branch);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/branches/prices', [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'variants' => $this->paginatedVariants($branch, $filters),
            'filters' => $filters,
            'options' => ['categories' => $this->categoryOptions()],
            'summary' => $this->summaryFor($branch),
            'can' => ['manage' => $actor->can('managePrices', $branch)],
        ]);
    }

    public function store(
        StoreBranchProductPriceRequest $request,
        Branch $branch,
        ProductVariant $variant,
        SetBranchProductPrice $setPrice,
    ): RedirectResponse {
        $this->authorize('managePrices', $branch);

        $setPrice->handle($branch, $variant, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Harga khusus untuk {$variant->sku} di cabang {$branch->name} disimpan.",
        ]);

        return back();
    }

    /**
     * Removes the branch's price, putting the variant back on the catalogue's.
     *
     * A delete and not a price of zero: zero means the branch gives the
     * variant away, and that has to stay sayable.
     */
    public function destroy(
        Branch $branch,
        ProductVariant $variant,
        SetBranchProductPrice $setPrice,
    ): RedirectResponse {
        $this->authorize('managePrices', $branch);

        $price = BranchProductPrice::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->first();

        abort_if($price === null, 404);

        $setPrice->clear($price);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$variant->sku} kini memakai harga katalog di cabang {$branch->name}.",
        ]);

        return back();
    }

    /**
     * @param  array{search: string, category: string, pricing: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedVariants(Branch $branch, array $filters): LengthAwarePaginator
    {
        $paginator = $this->listQuery($branch, $filters)->paginate(self::PER_PAGE)->withQueryString();

        /** @var Collection<int, ProductVariant> $variants */
        $variants = $paginator->getCollection();
        $resolved = app(EffectivePriceResolver::class)->forMany($variants, $branch);

        return $paginator->through(function (ProductVariant $variant) use ($branch, $resolved): array {
            $price = $variant->branchPrices->firstWhere('branch_id', $branch->getKey());
            $effective = $resolved[$variant->getKey()];

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'display_name' => $variant->displayName(),
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'category_name' => $variant->product->category->name,
                'is_active' => $variant->is_active,
                'catalogue_price' => $variant->effectivePrice(),
                'effective_price' => $effective->amount,
                'effective_source' => $effective->source->value,
                'effective_source_label' => $effective->source->label(),
                'compare_at_price' => $effective->compareAtPrice,
                'branch_price' => $price === null ? null : [
                    'id' => $price->id,
                    'price' => $price->price,
                    'compare_at_price' => $price->compare_at_price,
                    // `datetime-local` wants `YYYY-MM-DDTHH:MM` in the app's
                    // own timezone, not an ISO string with an offset.
                    'starts_at' => $price->starts_at?->format('Y-m-d\TH:i'),
                    'ends_at' => $price->ends_at?->format('Y-m-d\TH:i'),
                    'is_active' => $price->is_active,
                    'state' => $price->state(),
                    'is_in_force' => $price->isInForce(),
                ],
            ];
        });
    }

    /**
     * @param  array{search: string, category: string, pricing: string, sort: string, direction: string}  $filters
     * @return Builder<ProductVariant>
     */
    private function listQuery(Branch $branch, array $filters): Builder
    {
        return ProductVariant::query()
            ->with([
                'product:id,name,category_id,base_price,compare_at_price,cost_price,weight_gram',
                'product.category:id,name',
                'branchPrices' => fn ($prices) => $prices->where('branch_id', $branch->getKey()),
            ])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('sku', 'like', "%{$filters['search']}%")
                    ->orWhere('barcode', 'like', "%{$filters['search']}%")
                    ->orWhere('name', 'like', "%{$filters['search']}%")
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('name', 'like', "%{$filters['search']}%")),
            ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->whereHas('product', fn (Builder $product) => $product
                    ->whereIn('category_id', $this->categoryBranchIds((int) $filters['category']))))
            ->when($filters['pricing'] === 'custom', fn (Builder $query) => $query
                ->whereHas('branchPrices', fn (Builder $prices) => $prices
                    ->where('branch_id', $branch->getKey())))
            ->when($filters['pricing'] === 'catalogue', fn (Builder $query) => $query
                ->whereDoesntHave('branchPrices', fn (Builder $prices) => $prices
                    ->where('branch_id', $branch->getKey())))
            ->orderBy($filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * How many variants this branch prices for itself, and how many of those
     * are actually in force right now.
     *
     * @return array{total: int, custom: int, in_force: int}
     */
    private function summaryFor(Branch $branch): array
    {
        $prices = BranchProductPrice::acrossBranches()
            ->where('branch_id', $branch->getKey())
            ->get();

        return [
            'total' => ProductVariant::query()->count(),
            'custom' => $prices->count(),
            'in_force' => $prices->filter(fn (BranchProductPrice $price): bool => $price->isInForce())->count(),
        ];
    }

    /**
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
