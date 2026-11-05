<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Inventory\CancelStockDocument;
use App\Actions\Inventory\CompleteStockOpname;
use App\Actions\Inventory\CreateStockOpname;
use App\Actions\Inventory\RecordStockOpnameCounts;
use App\Enums\StockOpnameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\IndexStockOpnameRequest;
use App\Http\Requests\Branch\StoreStockOpnameRequest;
use App\Http\Requests\Branch\UpdateStockOpnameCountsRequest;
use App\Models\Category;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BranchWorkspace;
use App\Support\CategoryTree;
use App\Support\StockDocumentPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Physical stock counts (P1-E4-T7).
 *
 * Three steps, each its own endpoint, because a count is not one action:
 *
 * 1. **Open** the session, which snapshots what the system believes for every
 *    line it will cover.
 * 2. **Record** what was found on the shelves, as often as needed. Nothing
 *    moves; a long count can be saved and resumed.
 * 3. **Complete** it, which posts the variance of each counted line to the
 *    ledger.
 *
 * Steps 2 and 3 are separate permissions on purpose: the person walking the
 * shelves should not also be the person approving what they found, so warehouse
 * staff hold `stock_opnames.create` while `stock_opnames.complete` belongs to a
 * branch admin or the centre (docs/04-rbac.md).
 *
 * Completion posts the **variance**, not the counted figure — see
 * `CompleteStockOpname` for why that keeps a count from undoing the sales that
 * happened during it.
 */
class StockOpnameController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexStockOpnameRequest $request): Response
    {
        $this->authorize('viewAny', StockOpname::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());
        $filters = $request->filters();

        return Inertia::render('branch/stock-opnames/index', [
            ...$workspace->toArray(),
            'opnames' => $this->paginatedOpnames($workspace->warehouse, $filters),
            'filters' => $filters,
            'options' => [
                'statuses' => StockOpnameStatus::options(),
                'categories' => $this->categoryOptions(),
            ],
            'can' => ['create' => $actor->can('create', StockOpname::class)],
        ]);
    }

    public function store(StoreStockOpnameRequest $request, CreateStockOpname $createOpname): RedirectResponse
    {
        $this->authorize('create', StockOpname::class);

        $warehouse = $this->reachableWarehouse($request->integer('warehouse_id'));

        /** @var User $actor */
        $actor = $request->user();
        $opname = $createOpname->handle($warehouse, $request->validated(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Sesi opname {$opname->code} dibuka dengan {$opname->items()->count()} baris untuk dihitung.",
        ]);

        return to_route('branch.stock-opnames.show', $opname);
    }

    /**
     * The counting sheet: every line with what the system believed and what
     * has been counted so far.
     */
    public function show(StockOpname $opname): Response
    {
        $this->authorize('view', $opname);

        /** @var User $actor */
        $actor = request()->user();
        $opname->load(['items.productVariant.product', 'warehouse', 'createdBy:id,name', 'approvedBy:id,name']);
        $workspace = BranchWorkspace::resolve($actor, $opname->branch_id, $opname->warehouse_id);

        return Inertia::render('branch/stock-opnames/show', [
            ...$workspace->toArray(),
            'opname' => StockDocumentPresenter::opname($opname),
            'can' => [
                'record_counts' => $actor->can('recordCounts', $opname),
                'complete' => $actor->can('complete', $opname),
                'cancel' => $actor->can('cancel', $opname),
            ],
        ]);
    }

    /**
     * Saves what has been counted so far, without finishing the count.
     */
    public function recordCounts(
        UpdateStockOpnameCountsRequest $request,
        StockOpname $opname,
        RecordStockOpnameCounts $recordCounts,
    ): RedirectResponse {
        $this->authorize('recordCounts', $opname);

        $touched = $recordCounts->handle($opname, $request->counts());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Hasil hitung {$touched} baris disimpan. Sesi belum ditutup, jadi bisa dilanjutkan nanti.",
        ]);

        return back();
    }

    /**
     * Closes the count, posting each counted line's variance to the ledger.
     */
    public function complete(StockOpname $opname, CompleteStockOpname $completeOpname): RedirectResponse
    {
        $this->authorize('complete', $opname);

        /** @var User $actor */
        $actor = request()->user();
        $completed = $completeOpname->handle($opname, $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Opname {$completed->code} selesai. Selisihnya sudah tercatat di riwayat stok.",
        ]);

        return back();
    }

    public function cancel(StockOpname $opname, CancelStockDocument $cancelDocument): RedirectResponse
    {
        $this->authorize('cancel', $opname);

        $cancelDocument->handle($opname);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Sesi opname {$opname->code} dibatalkan tanpa mengubah stok.",
        ]);

        return back();
    }

    /**
     * The warehouse a new session belongs to, refused when it is not the
     * actor's to touch (BR-3).
     */
    private function reachableWarehouse(int $warehouseId): Warehouse
    {
        return Warehouse::query()->findOrFail($warehouseId);
    }

    /**
     * @param  array{search: string, status: string, reason: string, from: string, until: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedOpnames(Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        return StockOpname::acrossBranches()
            ->with(['createdBy:id,name', 'approvedBy:id,name'])
            ->withCount([
                'items',
                // How far along the count is, which is the one thing the
                // listing cannot work out from the header alone.
                'items as counted_items_count' => fn ($query) => $query->whereNotNull('counted_quantity'),
            ])
            ->where('warehouse_id', $warehouse->getKey())
            ->when($filters['search'] !== '', fn ($query) => $query
                ->where('code', 'like', "%{$filters['search']}%"))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['from'] !== '', fn ($query) => $query
                ->where('counted_at', '>=', Carbon::parse($filters['from'])->toDateString()))
            ->when($filters['until'] !== '', fn ($query) => $query
                ->where('counted_at', '<=', Carbon::parse($filters['until'])->toDateString()))
            ->latestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockOpname $opname): array => StockDocumentPresenter::opnameRow($opname));
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
