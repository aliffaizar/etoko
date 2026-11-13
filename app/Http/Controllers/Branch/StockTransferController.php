<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\CancelStockTransfer;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RejectStockTransfer;
use App\Actions\Inventory\RequestStockTransfer;
use App\Actions\Inventory\ShipStockTransfer;
use App\Enums\StockTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\AdvanceStockTransferRequest;
use App\Http\Requests\Branch\IndexStockTransferRequest;
use App\Http\Requests\Branch\RejectStockTransferRequest;
use App\Http\Requests\Branch\StoreStockTransferRequest;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BranchWorkspace;
use App\Support\StockTransferListQuery;
use App\Support\StockTransferPresenter;
use App\Support\StockVariantOptions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock moving between branches (P2-E3-T3, T4).
 *
 * The destination asks from its own warehouse; the origin (or central)
 * approves or rejects; the origin ships, which is when its stock leaves; the
 * destination receives what actually arrived. Each step is its own route and
 * its own policy ability, because each belongs to a different end of the
 * transfer.
 *
 * Route binding goes through `StockTransferBranchScope`, so a branch that is
 * neither end of a transfer gets a 404 for it, not a 403 (T5).
 */
class StockTransferController extends Controller
{
    public function index(IndexStockTransferRequest $request): Response
    {
        $this->authorize('viewAny', StockTransfer::class);

        /** @var User $actor */
        $actor = $request->user();
        $list = StockTransferListQuery::for($actor, $request->branchId(), $request->filters());

        return Inertia::render('branch/stock-transfers/index', [
            'transfers' => $list->paginate(),
            'summary' => $list->summary(),
            'filters' => $request->filters(),
            'perspective' => $list->perspective(),
            'options' => [
                'statuses' => StockTransferStatus::options(),
                'branches' => $actor->isCentral() ? $this->branchOptions(activeOnly: false) : [],
            ],
            'may_choose_branch' => $actor->isCentral(),
            'can' => ['create' => $actor->can('create', StockTransfer::class)],
        ]);
    }

    public function create(IndexStockTransferRequest $request): Response
    {
        $this->authorize('create', StockTransfer::class);

        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId(), $request->warehouseId());

        return Inertia::render('branch/stock-transfers/create', [
            ...$workspace->toArray(),
            'originOptions' => $this->branchOptions(except: $workspace->branch->id),
            'variantOptions' => StockVariantOptions::search($workspace->warehouse, $request->variantSearch()),
        ]);
    }

    public function store(StoreStockTransferRequest $request, RequestStockTransfer $requestTransfer): RedirectResponse
    {
        $this->authorize('create', StockTransfer::class);

        /** @var User $actor */
        $actor = $request->user();

        // The branch scope narrows this for branch staff: a warehouse of
        // another branch is not found, so nobody asks on a branch's behalf.
        $warehouse = Warehouse::query()->findOrFail($request->integer('warehouse_id'));
        $origin = Branch::query()->findOrFail($request->integer('from_branch_id'));

        $transfer = $requestTransfer->handle($warehouse, $origin, $request->transferAttributes(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Permintaan {$transfer->code} dikirim ke {$origin->name}. Stok belum berpindah sampai barang dikirim.",
        ]);

        return to_route('branch.stock-transfers.show', $transfer);
    }

    public function show(StockTransfer $transfer): Response
    {
        $this->authorize('view', $transfer);

        /** @var User $actor */
        $actor = request()->user();
        $transfer->load([
            'items.productVariant.product', 'fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse',
            'requestedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name',
            'shippedBy:id,name', 'receivedBy:id,name', 'cancelledBy:id,name',
        ]);

        $can = [
            'approve' => $actor->can('approve', $transfer),
            'reject' => $actor->can('reject', $transfer),
            'ship' => $actor->can('ship', $transfer),
            'receive' => $actor->can('receive', $transfer),
            'cancel' => $actor->can('cancel', $transfer),
        ];

        return Inertia::render('branch/stock-transfers/show', [
            'transfer' => StockTransferPresenter::detail(
                $transfer,
                $actor->branch_id,
                showOriginStock: $can['approve'] || $can['ship'],
            ),
            'originWarehouses' => $can['approve'] ? StockTransferPresenter::originWarehouseOptions($transfer) : [],
            'can' => $can,
        ]);
    }

    public function approve(AdvanceStockTransferRequest $request, StockTransfer $transfer, ApproveStockTransfer $approve): RedirectResponse
    {
        $this->authorize('approve', $transfer);

        $approve->handle($transfer, [
            'from_warehouse_id' => $request->fromWarehouseId(),
            'items' => $request->lines(),
        ], $request->user());

        return $this->done("{$transfer->code} disetujui. Cabang asal tinggal mengirim barangnya.");
    }

    public function reject(RejectStockTransferRequest $request, StockTransfer $transfer, RejectStockTransfer $reject): RedirectResponse
    {
        $this->authorize('reject', $transfer);

        $reject->handle($transfer, $request->reason(), $request->user());

        return $this->done("{$transfer->code} ditolak. Cabang peminta akan melihat alasannya.");
    }

    public function ship(AdvanceStockTransferRequest $request, StockTransfer $transfer, ShipStockTransfer $ship): RedirectResponse
    {
        $this->authorize('ship', $transfer);

        $shipped = $ship->handle($transfer, $request->lines(), $request->user());
        $units = $shipped->items->sum('quantity_shipped');

        return $this->done("{$shipped->code} dikirim: {$units} unit keluar dari {$shipped->fromWarehouse->name}.");
    }

    public function receive(AdvanceStockTransferRequest $request, StockTransfer $transfer, ReceiveStockTransfer $receive): RedirectResponse
    {
        $this->authorize('receive', $transfer);

        $received = $receive->handle($transfer, $request->lines(), $request->receiptNotes(), $request->user());

        return $this->done($received->status === StockTransferStatus::Received
            ? "{$received->code} diterima lengkap dan masuk ke {$received->toWarehouse->name}."
            : "{$received->code} diterima dengan selisih {$received->totalDiscrepancy()} unit. Selisihnya tercatat di dokumen.");
    }

    public function cancel(StockTransfer $transfer, CancelStockTransfer $cancel): RedirectResponse
    {
        $this->authorize('cancel', $transfer);

        $cancel->handle($transfer, request()->user());

        return $this->done("Permintaan {$transfer->code} dibatalkan.");
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Branches for the origin picker (active only: an inactive branch cannot
     * be asked for stock) and for the central branch filter (all of them:
     * old transfers of a closed branch are still worth finding).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(?int $except = null, bool $activeOnly = true): array
    {
        return Branch::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->ordered()
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => "{$branch->name} ({$branch->code})",
            ])
            ->all();
    }
}
