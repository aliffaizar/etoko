<?php

namespace App\Http\Controllers\Branch;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\IndexPackingRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\BranchWorkspace;
use App\Support\PackingListPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The warehouse's part of an order: the packing list (P3-E6-T4).
 *
 * Paid orders of the branch, oldest payment first, each split into what to
 * take from which warehouse and shelf (`PackingListPresenter`). One action
 * lives here — "Mulai kemas", `paid` → `processing` — so warehouse staff can
 * say they have the order in hand without being able to ship, hand over or
 * cancel it; those stay on the order screen with `orders.update_status`.
 *
 * The branch comes from `BranchWorkspace`, so branch staff see their own and
 * central staff pick one with `?branch=`, as on the stock screens.
 */
class PackingController extends Controller
{
    private const PER_PAGE = 15;

    public function index(IndexPackingRequest $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $workspace = BranchWorkspace::resolve($actor, $request->branchId());
        $filters = $request->filters();

        $place = $workspace->toArray();
        $orders = $this->paginatedOrders($workspace->branch->getKey(), $filters);

        return Inertia::render('branch/packing/index', [
            'branch' => $place['branch'],
            'branches' => $place['branches'],
            'may_choose_branch' => $place['may_choose_branch'],
            'orders' => $orders->setCollection(collect(PackingListPresenter::orders($orders->getCollection()))),
            'counts' => $this->countsFor($workspace->branch->getKey()),
            'filters' => $filters,
        ]);
    }

    /**
     * Marks a paid order as being packed.
     *
     * A second click — or a colleague who pressed first — finds the order
     * already past `paid`; `TransitionOrderStatus` refuses under its row lock
     * and the list says so instead of showing an error page.
     */
    public function start(Request $request, Order $order, TransitionOrderStatus $transition): RedirectResponse
    {
        $this->authorize('pack', $order);

        try {
            $transition->handle($order, OrderStatus::Processing, $request->user(), 'Mulai dikemas di gudang.');
        } catch (IllegalOrderTransitionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Pesanan {$order->order_number} sudah tidak menunggu dikemas."]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pesanan {$order->order_number} sedang dikemas."]);

        return back();
    }

    /**
     * @param  array{stage: string, search: string}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    private function paginatedOrders(int $branchId, array $filters): LengthAwarePaginator
    {
        $status = $filters['stage'] === 'packing' ? OrderStatus::Processing : OrderStatus::Paid;

        return Order::acrossBranches()
            ->with(['items', 'stockReservations.warehouse'])
            ->where('branch_id', $branchId)
            ->where('status', $status)
            ->when($filters['search'] !== '', fn ($query) => $query
                ->where(fn ($match) => $match
                    ->where('order_number', 'like', '%'.$filters['search'].'%')
                    ->orWhere('customer_name', 'like', '%'.$filters['search'].'%')))
            ->orderBy($status === OrderStatus::Paid ? 'paid_at' : 'processed_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return array{to_pack: int, packing: int}
     */
    private function countsFor(int $branchId): array
    {
        $counts = Order::acrossBranches()
            ->where('branch_id', $branchId)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Processing])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'to_pack' => (int) ($counts[OrderStatus::Paid->value] ?? 0),
            'packing' => (int) ($counts[OrderStatus::Processing->value] ?? 0),
        ];
    }
}
