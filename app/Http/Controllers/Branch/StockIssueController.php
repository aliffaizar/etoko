<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Inventory\CancelStockDocument;
use App\Actions\Inventory\CreateStockIssue;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\UpdateStockIssue;
use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\IndexStockDocumentRequest;
use App\Http\Requests\Branch\StoreStockIssueRequest;
use App\Models\StockIssue;
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
 * Goods leaving a warehouse without being sold (P1-E4-T6).
 *
 * The mirror of `StockEntryController`, with two differences that matter:
 *
 * - Every document says **why** the goods left. Shrinkage nobody can count by
 *   cause is shrinkage nobody can act on.
 * - Posting can fail. The warehouse may simply not hold enough, and the answer
 *   changes between the render and the click — so it comes back from
 *   `StockLedger` as a validation error on the lines rather than being guessed
 *   earlier (BR-6). When it fails, nothing is issued at all.
 *
 * There is no delete: an abandoned note is cancelled and stays visible. A
 * record of something going missing that can be made to disappear is a gap in
 * exactly the trail that matters.
 */
class StockIssueController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexStockDocumentRequest $request): Response
    {
        $this->authorize('viewAny', StockIssue::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());
        $filters = $request->filters();

        return Inertia::render('branch/stock-issues/index', [
            ...$workspace->toArray(),
            'issues' => $this->paginatedIssues($workspace->warehouse, $filters),
            'filters' => $filters,
            'options' => [
                'statuses' => StockDocumentStatus::options(),
                'reasons' => StockIssueReason::options(),
            ],
            'summary' => StockDocumentPresenter::summaryFor(
                StockIssue::acrossBranches()->where('warehouse_id', $workspace->warehouse->getKey()),
            ),
            'can' => ['create' => $actor->can('create', StockIssue::class)],
        ]);
    }

    public function create(IndexStockDocumentRequest $request): Response
    {
        $this->authorize('create', StockIssue::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());

        return Inertia::render('branch/stock-issues/form', [
            ...$workspace->toArray(),
            'issue' => null,
            'variantOptions' => StockVariantOptions::search($workspace->warehouse, $request->variantSearch()),
            'options' => ['reasons' => StockIssueReason::options()],
            'can' => ['update' => true, 'post' => false, 'cancel' => false],
        ]);
    }

    public function store(StoreStockIssueRequest $request, CreateStockIssue $createIssue): RedirectResponse
    {
        $this->authorize('create', StockIssue::class);

        $warehouse = $this->reachableWarehouse($request->integer('warehouse_id'));

        /** @var User $actor */
        $actor = $request->user();
        $issue = $createIssue->handle($warehouse, $request->validated(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Draf barang keluar {$issue->code} dibuat. Periksa barisnya, lalu posting untuk mengurangi stok.",
        ]);

        return to_route('branch.stock-issues.edit', $issue);
    }

    public function edit(IndexStockDocumentRequest $request, StockIssue $issue): Response
    {
        $this->authorize('view', $issue);

        /** @var User $actor */
        $actor = $request->user();
        $issue->load(['items.productVariant.product', 'warehouse', 'createdBy:id,name', 'postedBy:id,name']);
        $workspace = BranchWorkspace::resolve($actor, $issue->branch_id, $issue->warehouse_id);

        return Inertia::render('branch/stock-issues/form', [
            ...$workspace->toArray(),
            'issue' => StockDocumentPresenter::issue($issue),
            'variantOptions' => StockVariantOptions::search($workspace->warehouse, $request->variantSearch()),
            'options' => ['reasons' => StockIssueReason::options()],
            'can' => [
                'update' => $actor->can('update', $issue),
                'post' => $actor->can('post', $issue),
                'cancel' => $actor->can('cancel', $issue),
            ],
        ]);
    }

    public function update(
        StoreStockIssueRequest $request,
        StockIssue $issue,
        UpdateStockIssue $updateIssue,
    ): RedirectResponse {
        $this->authorize('update', $issue);

        // `warehouse_id` is deliberately ignored, as on a goods-in document:
        // the lines were typed against one warehouse's shelves.
        $updateIssue->handle($issue, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draf {$issue->code} disimpan."]);

        return back();
    }

    /**
     * Commits the document, which is the moment the stock leaves.
     *
     * A shortfall surfaces as a validation error on `items`, because the fix
     * is a smaller quantity on a line — which is only typeable if the form is
     * still there.
     */
    public function post(StockIssue $issue, PostStockIssue $postIssue): RedirectResponse
    {
        $this->authorize('post', $issue);

        /** @var User $actor */
        $actor = request()->user();
        $posted = $postIssue->handle($issue, $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$posted->code} diposting: {$posted->totalQuantity()} unit keluar dari {$posted->warehouse->name}.",
        ]);

        return back();
    }

    public function cancel(StockIssue $issue, CancelStockDocument $cancelDocument): RedirectResponse
    {
        $this->authorize('cancel', $issue);

        $cancelDocument->handle($issue);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draf {$issue->code} dibatalkan."]);

        return back();
    }

    /**
     * The warehouse a new document belongs to, refused when it is not the
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
    private function paginatedIssues(Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        return StockIssue::acrossBranches()
            ->with(['createdBy:id,name', 'postedBy:id,name'])
            ->withSum('items', 'quantity')
            ->withCount('items')
            ->where('warehouse_id', $warehouse->getKey())
            ->when($filters['search'] !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('code', 'like', "%{$filters['search']}%")
                    ->orWhere('reference_no', 'like', "%{$filters['search']}%"),
            ))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['reason'] !== '', fn ($query) => $query->where('reason', $filters['reason']))
            ->when($filters['from'] !== '', fn ($query) => $query
                ->where('issue_date', '>=', Carbon::parse($filters['from'])->toDateString()))
            ->when($filters['until'] !== '', fn ($query) => $query
                ->where('issue_date', '<=', Carbon::parse($filters['until'])->toDateString()))
            ->latestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockIssue $issue): array => StockDocumentPresenter::issueRow($issue));
    }
}
