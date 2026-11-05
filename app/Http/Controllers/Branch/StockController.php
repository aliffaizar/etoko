<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\SetStockThresholds;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\AdjustStockRequest;
use App\Http\Requests\Branch\IndexStockMovementRequest;
use App\Http\Requests\Branch\IndexStockRequest;
use App\Http\Requests\Branch\UpdateStockThresholdsRequest;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BranchWorkspace;
use App\Support\CategoryTree;
use App\Support\StockListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a branch holds, and the two ways it is corrected by hand
 * (P1-E4-T3, T4).
 *
 * The listing is over variants rather than over `stocks` rows, because a row
 * only exists once something has been said about the pair — see
 * `StockListQuery` for why that matters. Each row therefore always has a
 * figure, even if that figure is zero.
 *
 * Four numbers per row, and they are not interchangeable: **on-hand** is what
 * is on the shelf, **reserved** is what checkout has already promised a buyer,
 * **available** is the difference and the only one a sale may draw on (BR-6),
 * and **minimum** is the line below which somebody wants to be told.
 *
 * Unlike the catalogue, this is the branch's own business: a branch admin and
 * their warehouse staff correct their own figures without going through the
 * centre (`BranchPolicy::adjustStock`). A cashier reads the screen and finds
 * both forms absent, because they sell stock rather than count it.
 */
class StockController extends Controller
{
    private const PER_PAGE = 20;

    private const MOVEMENTS_PER_PAGE = 25;

    public function index(IndexStockRequest $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());

        $this->authorize('viewStock', $workspace->branch);

        $filters = $request->filters();

        return Inertia::render('branch/stocks/index', [
            ...$workspace->toArray(),
            'variants' => $this->paginatedVariants($workspace->warehouse, $filters),
            'filters' => $filters,
            'options' => ['categories' => $this->categoryOptions()],
            'summary' => $this->summaryFor($workspace->warehouse),
            'can' => ['adjust' => $actor->can('adjustStock', $workspace->branch)],
        ]);
    }

    /**
     * Corrects one variant's figure in one warehouse by hand (P1-E4-T2).
     */
    public function adjust(
        AdjustStockRequest $request,
        ProductVariant $variant,
        AdjustStock $adjustStock,
    ): RedirectResponse {
        $warehouse = $this->reachableWarehouse($request->integer('warehouse_id'));

        $this->authorize('adjustStock', $warehouse->branch);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $movement = $adjustStock->handle(
                $warehouse,
                $variant,
                $request->integer('quantity_change'),
                (string) $request->string('notes'),
                $actor,
            );
        } catch (InsufficientStockException $shortfall) {
            // Shown next to the number they typed, rather than as an error
            // page: the figure moved under them and the fix is a smaller
            // number, which they can only type if the form is still there.
            throw ValidationException::withMessages(['quantity_change' => $shortfall->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Stok {$variant->sku} di {$warehouse->name} kini {$movement->quantity_after} unit.",
        ]);

        return back();
    }

    /**
     * Sets reorder thresholds for a batch of variants at once (P1-E4-T4).
     */
    public function updateThresholds(
        UpdateStockThresholdsRequest $request,
        SetStockThresholds $setThresholds,
    ): RedirectResponse {
        $warehouse = $this->reachableWarehouse($request->integer('warehouse_id'));

        $this->authorize('adjustStock', $warehouse->branch);

        /** @var array<int, int> $variantIds */
        $variantIds = $request->validated('variant_ids');

        $variants = ProductVariant::query()->findMany($variantIds)->all();

        $touched = $setThresholds->handle($warehouse, $variants, [
            'min_quantity' => $request->integer('min_quantity'),
            'max_quantity' => $request->input('max_quantity') === null
                ? null
                : $request->integer('max_quantity'),
            'shelf_location' => $request->input('shelf_location') === null
                ? null
                : (string) $request->string('shelf_location'),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Ambang stok {$touched} varian di {$warehouse->name} disimpan.",
        ]);

        return back();
    }

    /**
     * One variant's stock history in one warehouse, read from the ledger
     * (P1-E4-T8).
     *
     * Read straight from `stock_movements` and never recomputed: the point of
     * the screen is to explain the figure on the stock listing, and a second
     * calculation of that figure could disagree with it. Each row carries the
     * figure before and after, so the column reads as an audit trail rather
     * than a list of deltas.
     *
     * Newest first, because the question that brings somebody here is almost
     * always "what happened just now?".
     */
    public function movements(IndexStockMovementRequest $request, ProductVariant $variant): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());

        $this->authorize('viewStock', $workspace->branch);

        $filters = $request->filters();
        $stock = Stock::orZeroed(
            Stock::acrossBranches()
                ->where('warehouse_id', $workspace->warehouse->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->first()
        );

        return Inertia::render('branch/stocks/movements', [
            ...$workspace->toArray(),
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'display_name' => $variant->displayName(),
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
            ],
            'stock' => [
                'on_hand' => $stock->quantity,
                'reserved' => $stock->reserved_quantity,
                'available' => $stock->available_quantity,
                'min_quantity' => $stock->min_quantity,
                'shelf_location' => $stock->shelf_location,
                'is_low' => $stock->isLow(),
            ],
            'movements' => $this->paginatedMovements($workspace->warehouse, $variant, $filters),
            'filters' => $filters,
            'options' => ['types' => StockMovementType::options()],
        ]);
    }

    /**
     * @param  array{type: string, from: string, until: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedMovements(Warehouse $warehouse, ProductVariant $variant, array $filters): LengthAwarePaginator
    {
        return StockMovement::acrossBranches()
            ->with('createdBy:id,name')
            ->where('warehouse_id', $warehouse->getKey())
            ->forVariant($variant->getKey())
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['from'] !== '', fn ($query) => $query
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['until'] !== '', fn ($query) => $query
                ->where('created_at', '<=', Carbon::parse($filters['until'])->endOfDay()))
            ->latestFirst()
            ->paginate(self::MOVEMENTS_PER_PAGE)
            ->withQueryString()
            ->through(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'quantity_change' => $movement->quantity_change,
                'quantity_before' => $movement->quantity_before,
                'quantity_after' => $movement->quantity_after,
                'unit_cost' => $movement->unit_cost,
                'notes' => $movement->notes,
                'reference_label' => $this->referenceLabel($movement),
                'created_by' => $movement->createdBy?->name,
                'created_at' => $movement->created_at?->toIso8601String(),
            ]);
    }

    /**
     * How the document behind a movement reads in the history.
     *
     * The document types arrive with their own epics; until then a movement
     * with a reference still has to render as something, so it falls back to
     * the morph alias rather than an empty cell.
     */
    private function referenceLabel(StockMovement $movement): ?string
    {
        if ($movement->reference_type === null) {
            return null;
        }

        $code = $movement->reference?->getAttribute('code');

        return is_string($code) ? $code : class_basename($movement->reference_type);
    }

    /**
     * The warehouse a write is aimed at, refused outright when it is not the
     * actor's to touch.
     *
     * The warehouse arrives in the body rather than the URL because both forms
     * act on the warehouse the screen is looking at. `Warehouse` carries the
     * branch global scope, so a branch account naming another branch's
     * warehouse gets a 404 from this lookup before any policy is consulted
     * (BR-3); the policy then has the last word for a central actor.
     */
    private function reachableWarehouse(int $warehouseId): Warehouse
    {
        return Warehouse::query()->with('branch')->findOrFail($warehouseId);
    }

    /**
     * @param  array{search: string, category: string, state: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedVariants(Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        return StockListQuery::ordered($warehouse, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(function (ProductVariant $variant) use ($warehouse): array {
                $stock = Stock::orZeroed($variant->stocks->firstWhere('warehouse_id', $warehouse->getKey()));

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'display_name' => $variant->displayName(),
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'category_name' => $variant->product->category->name,
                    'is_active' => $variant->is_active,
                    'on_hand' => $stock->quantity,
                    'reserved' => $stock->reserved_quantity,
                    'available' => $stock->available_quantity,
                    'min_quantity' => $stock->min_quantity,
                    'max_quantity' => $stock->max_quantity,
                    'shelf_location' => $stock->shelf_location,
                    'is_low' => $stock->isLow(),
                ];
            });
    }

    /**
     * How the warehouse is doing in one line.
     *
     * Counted over `stocks` rather than over the catalogue, because "variants
     * we hold nothing of" is already the whole of the catalogue minus these.
     *
     * @return array{variants: int, stocked: int, low: int, on_hand: int, reserved: int}
     */
    private function summaryFor(Warehouse $warehouse): array
    {
        $rows = Stock::acrossBranches()->where('warehouse_id', $warehouse->getKey())->get();

        return [
            'variants' => ProductVariant::query()->count(),
            'stocked' => $rows->where('quantity', '>', 0)->count(),
            'low' => $rows->filter(fn (Stock $stock): bool => $stock->isLow())->count(),
            'on_hand' => (int) $rows->sum('quantity'),
            'reserved' => (int) $rows->sum('reserved_quantity'),
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
