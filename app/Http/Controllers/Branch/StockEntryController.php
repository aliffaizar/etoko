<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Inventory\CancelStockDocument;
use App\Actions\Inventory\CreateStockEntry;
use App\Actions\Inventory\PostStockEntry;
use App\Actions\Inventory\UpdateStockEntry;
use App\Enums\StockDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\IndexStockDocumentRequest;
use App\Http\Requests\Branch\StoreStockEntryRequest;
use App\Models\StockEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BranchWorkspace;
use App\Support\StockDocumentPresenter;
use App\Support\StockVariantOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods arriving at a warehouse (P1-E4-T5).
 *
 * The screen is built around one distinction: a **draft** is being typed and
 * can be changed freely, while a **posted** document has moved the figures and
 * is history. Posting is therefore its own endpoint and its own permission,
 * not a status field on the form — "save" and "commit stock" must not be the
 * same click.
 *
 * Receiving goods is the branch's own work, so unlike the catalogue this is
 * not central-only: a branch admin and their warehouse staff draft and post
 * their own notes, narrowed to their branch by BR-3.
 */
class StockEntryController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexStockDocumentRequest $request): Response
    {
        $this->authorize('viewAny', StockEntry::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());
        $filters = $request->filters();

        return Inertia::render('branch/stock-entries/index', [
            ...$workspace->toArray(),
            'entries' => $this->paginatedEntries($workspace->warehouse, $filters),
            'filters' => $filters,
            'options' => ['statuses' => StockDocumentStatus::options()],
            'summary' => StockDocumentPresenter::summaryFor(
                StockEntry::acrossBranches()->where('warehouse_id', $workspace->warehouse->getKey()),
            ),
            'can' => ['create' => $actor->can('create', StockEntry::class)],
        ]);
    }

    public function create(IndexStockDocumentRequest $request): Response
    {
        $this->authorize('create', StockEntry::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());

        return Inertia::render('branch/stock-entries/form', [
            ...$workspace->toArray(),
            'entry' => null,
            'variantOptions' => StockVariantOptions::search($workspace->warehouse, $request->variantSearch()),
            'can' => ['update' => true, 'post' => false, 'cancel' => false],
        ]);
    }

    public function store(StoreStockEntryRequest $request, CreateStockEntry $createEntry): RedirectResponse
    {
        $this->authorize('create', StockEntry::class);

        $warehouse = $this->reachableWarehouse($request->integer('warehouse_id'));

        /** @var User $actor */
        $actor = $request->user();
        $entry = $createEntry->handle($warehouse, $request->validated(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Draf barang masuk {$entry->code} dibuat. Periksa barisnya, lalu posting untuk menambah stok.",
        ]);

        return to_route('branch.stock-entries.edit', $entry);
    }

    public function edit(IndexStockDocumentRequest $request, StockEntry $entry): Response
    {
        $this->authorize('view', $entry);

        /** @var User $actor */
        $actor = $request->user();
        $entry->load(['items.productVariant.product', 'warehouse', 'createdBy:id,name', 'postedBy:id,name']);
        $workspace = BranchWorkspace::resolve($actor, $entry->branch_id, $entry->warehouse_id);

        return Inertia::render('branch/stock-entries/form', [
            ...$workspace->toArray(),
            'entry' => StockDocumentPresenter::entry($entry),
            'variantOptions' => StockVariantOptions::search($workspace->warehouse, $request->variantSearch()),
            'can' => [
                'update' => $actor->can('update', $entry),
                'post' => $actor->can('post', $entry),
                'cancel' => $actor->can('cancel', $entry),
                'delete' => $actor->can('delete', $entry),
            ],
        ]);
    }

    public function update(
        StoreStockEntryRequest $request,
        StockEntry $entry,
        UpdateStockEntry $updateEntry,
    ): RedirectResponse {
        $this->authorize('update', $entry);

        // `warehouse_id` is deliberately ignored: the lines were typed against
        // one warehouse's shelves, so moving the document to another would
        // apply them to the wrong ones.
        $updateEntry->handle($entry, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draf {$entry->code} disimpan."]);

        return back();
    }

    /**
     * Commits the document, which is the moment stock moves.
     */
    public function post(StockEntry $entry, PostStockEntry $postEntry): RedirectResponse
    {
        $this->authorize('post', $entry);

        /** @var User $actor */
        $actor = request()->user();
        $posted = $postEntry->handle($entry, $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$posted->code} diposting: {$posted->totalQuantity()} unit masuk ke {$posted->warehouse->name}.",
        ]);

        return back();
    }

    public function cancel(StockEntry $entry, CancelStockDocument $cancelDocument): RedirectResponse
    {
        $this->authorize('cancel', $entry);

        $cancelDocument->handle($entry);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draf {$entry->code} dibatalkan."]);

        return back();
    }

    public function destroy(StockEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        $code = $entry->code;
        $entry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draf {$code} dihapus."]);

        return to_route('branch.stock-entries.index');
    }

    /**
     * The warehouse a new document belongs to, refused when it is not the
     * actor's to touch.
     *
     * `Warehouse` carries the branch global scope, so a branch account naming
     * another branch's warehouse gets a 404 here before any policy is
     * consulted (BR-3).
     */
    private function reachableWarehouse(int $warehouseId): Warehouse
    {
        return Warehouse::query()->findOrFail($warehouseId);
    }

    /**
     * @param  array{search: string, status: string, reason: string, from: string, until: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginatedEntries(Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        return StockEntry::acrossBranches()
            ->with(['createdBy:id,name', 'postedBy:id,name'])
            ->withSum('items', 'quantity')
            ->withCount('items')
            ->where('warehouse_id', $warehouse->getKey())
            ->when($filters['search'] !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('code', 'like', "%{$filters['search']}%")
                    ->orWhere('supplier_name', 'like', "%{$filters['search']}%")
                    ->orWhere('reference_no', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['from'] !== '', fn ($query) => $query
                ->where('entry_date', '>=', Carbon::parse($filters['from'])->toDateString()))
            ->when($filters['until'] !== '', fn ($query) => $query
                ->where('entry_date', '<=', Carbon::parse($filters['until'])->toDateString()))
            ->latestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockEntry $entry): array => StockDocumentPresenter::entryRow($entry));
    }
}
