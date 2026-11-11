<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Returns\ApproveReturn;
use App\Actions\Returns\CompleteReturn;
use App\Actions\Returns\ReceiveReturn;
use App\Actions\Returns\RejectReturn;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReturnItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecideReturnRequest;
use App\Http\Requests\Admin\IndexReturnRequest;
use App\Http\Requests\Admin\ReceiveReturnRequest;
use App\Http\Requests\Admin\RejectReturnRequest;
use App\Models\Branch;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentPresenter;
use App\Support\RefundPresenter;
use App\Support\ReturnListQuery;
use App\Support\ReturnPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The return queue, for central and branch staff alike (P2-E4-T3).
 *
 * One screen for both, narrowed rather than duplicated (ADR-016):
 * `OrderReturn` carries `BelongsToBranch` with a `branch_id` copied from the
 * order (ADR-038), so a branch account's queue is already its own and the
 * branch filter is simply of no use to them.
 *
 * The screen's job is one question — **which parcels need me?** — and the
 * `open` filter is the one that answers it: three of the six statuses await
 * somebody, and which three is a rule on the enum rather than a choice made
 * here. The counts above the listing are built around the same distinction.
 *
 * Four writes, each its own decision: agreeing to take goods back, turning a
 * request down, booking the parcel in, and closing the request. All four go
 * through the actions in `App\Actions\Returns`, because the one that matters
 * most — receiving — puts units back on a shelf through `StockLedger` and
 * takes them off what the order counts as sold, and a controller writing
 * `status` by hand would do neither.
 *
 * **Sending the money back is not here.** It is `Admin\RefundController`, on
 * `refunds.process`, and it is central (ADR-049) — a branch admin takes goods
 * back without being able to pay for them. The refund form still appears on
 * *this* screen, because the person deciding the refund is reading this
 * request; what differs is who may submit it.
 *
 * Because `SubstituteBindings` runs after `SetBranchContext` (ADR-027),
 * another branch's return id in the URL answers **404** rather than 403.
 */
class ReturnController extends Controller
{
    private const PER_PAGE = 20;

    public function index(IndexReturnRequest $request): Response
    {
        $this->authorize('viewAny', OrderReturn::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/returns/index', [
            'returns' => $this->paginated($filters),
            'filters' => $filters,
            'summary' => $this->summary(),
            'options' => [
                'statuses' => ReturnStatus::options(),
                'types' => ReturnType::options(),
                'reasons' => ReturnReason::options(),
                // Branch accounts get no branch select: theirs is the only one
                // the queue can contain, so offering it would be a control
                // that cannot change anything.
                'branches' => $actor->isCentral() ? $this->branchOptions() : [],
            ],
            'is_central' => $actor->isCentral(),
            'can' => ['decide' => $actor->can('returns.approve')],
        ]);
    }

    public function show(OrderReturn $return): Response
    {
        $this->authorize('view', $return);

        /** @var User $actor */
        $actor = request()->user();

        $return->load([
            'order:id,order_number,customer_name,customer_email,customer_phone,status,payment_status,grand_total,branch_id,fulfillment_type,shipping_cost',
            'branch:id,name',
            'user:id,name',
            'approvedBy:id,name',
            'receivedBy:id,name',
            'items.orderItem',
            'items.restockWarehouse:id,name',
            'refunds.processedBy:id,name',
        ]);

        $mayDecide = $actor->can('decide', $return);

        return Inertia::render('admin/returns/show', [
            'return' => ReturnPresenter::detail($return, [
                'approve' => $mayDecide && $return->approvalBlockedReason() === null,
                'reject' => $mayDecide && $return->rejectionBlockedReason() === null,
                'receive' => $mayDecide && $return->receivingBlockedReason() === null,
                'complete' => $mayDecide && $return->status->canTransitionTo(ReturnStatus::Completed),
                'refund' => $actor->can('refund', $return->order),
                // Settling or failing a refund that already exists, which is
                // `RefundPolicy::process` rather than `OrderPolicy::refund`:
                // an order whose whole total has gone back may still have a
                // pending transfer somebody has to record.
                'process_refund' => $actor->isCentral() && $actor->can('refunds.process'),
            ], [
                'approve' => $return->approvalBlockedReason(),
                'reject' => $return->rejectionBlockedReason(),
                'receive' => $return->receivingBlockedReason(),
                'refund' => $return->refundBlockedReason(),
            ]),
            'conditions' => ReturnItemCondition::options(),
            // What the refund form on this screen needs: how much is left, how
            // it may go back, and which attempt the money came in on.
            'refund_options' => [
                'methods' => RefundPresenter::methodChoices(),
                'payments' => $this->refundablePayments($return),
                'refundable_amount' => $return->order->refundableAmount(),
                'suggested_amount' => $this->suggestedRefund($return),
            ],
            'refunds' => $return->refunds
                ->map(fn ($refund): array => RefundPresenter::row($refund))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Agree to take the goods back (P2-E4-T3).
     *
     * A promise and nothing more: no stock moves and no money moves, because
     * the parcel is still with the customer.
     */
    public function approve(
        DecideReturnRequest $request,
        OrderReturn $return,
        ApproveReturn $approveReturn,
    ): RedirectResponse {
        $this->authorize('decide', $return);

        /** @var User $actor */
        $actor = $request->user();

        $approveReturn->handle($return, $actor, $request->notes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Retur {$return->code} disetujui. Pelanggan dapat mengirimkan barangnya ke cabang.",
        ]);

        return back();
    }

    /**
     * Turn the request down (P2-E4-T3).
     *
     * The units it had committed go back to what the customer may still ask
     * for, so a rejection is never a dead end — a reason plus a corrected
     * request is the normal path.
     */
    public function reject(
        RejectReturnRequest $request,
        OrderReturn $return,
        RejectReturn $rejectReturn,
    ): RedirectResponse {
        $this->authorize('decide', $return);

        /** @var User $actor */
        $actor = $request->user();

        $rejectReturn->handle($return, $request->reason(), $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Retur {$return->code} ditolak. Pelanggan dapat mengajukan ulang bila perlu.",
        ]);

        return back();
    }

    /**
     * Book the parcel in (P2-E4-T3).
     *
     * The moment the figures move: sellable units go back on the shelf as
     * `sale_return_in`, and the order's lines stop counting them as sold. The
     * message is built from what actually happened rather than from what
     * usually happens, because telling staff their stock came back when it did
     * not is how goods go missing on paper (MED-03).
     */
    public function receive(
        ReceiveReturnRequest $request,
        OrderReturn $return,
        ReceiveReturn $receiveReturn,
    ): RedirectResponse {
        $this->authorize('decide', $return);

        /** @var User $actor */
        $actor = $request->user();

        $receiveReturn->handle($return, $request->lines(), $actor, $request->notes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Barang retur {$return->code} tercatat diterima. "
                .($receiveReturn->restockedUnits > 0
                    ? "{$receiveReturn->restockedUnits} unit dikembalikan ke stok cabang."
                    : 'Tidak ada unit yang dikembalikan ke stok.'),
        ]);

        return back();
    }

    /**
     * Close the request by hand (P2-E4-T3, T4).
     *
     * For the exchange no refund will ever close, and for the refund the shop
     * deliberately made smaller than the goods.
     */
    public function complete(
        DecideReturnRequest $request,
        OrderReturn $return,
        CompleteReturn $completeReturn,
    ): RedirectResponse {
        $this->authorize('decide', $return);

        /** @var User $actor */
        $actor = $request->user();

        $completeReturn->handle($return, $actor, $request->notes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Retur {$return->code} ditandai selesai.",
        ]);

        return back();
    }

    /**
     * @param  array{search: string, status: string, type: string, reason: string, branch: string, open: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters): LengthAwarePaginator
    {
        return ReturnListQuery::from($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderReturn $return): array => ReturnPresenter::row($return));
    }

    /**
     * The counts above the listing: what still needs doing.
     *
     * Deliberately **not** affected by the filters below them — they answer
     * "what is waiting for me?", which a narrowed listing must not change the
     * answer to. Narrowed by branch for a branch account by the global scope,
     * like everything else here.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $byStatus = OrderReturn::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'requested' => (int) ($byStatus[ReturnStatus::Requested->value] ?? 0),
            'approved' => (int) ($byStatus[ReturnStatus::Approved->value] ?? 0),
            'awaiting_refund' => OrderReturn::query()->awaitingRefund()->count(),
            'completed' => (int) ($byStatus[ReturnStatus::Completed->value] ?? 0),
            'refused' => (int) ($byStatus[ReturnStatus::Rejected->value] ?? 0)
                + (int) ($byStatus[ReturnStatus::Cancelled->value] ?? 0),
        ];
    }

    /**
     * The order's settled payments, as choices for a gateway refund.
     *
     * Only successful attempts: a provider will not reverse a transaction that
     * never went through, and offering one would be offering a refusal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function refundablePayments(OrderReturn $return): array
    {
        return Payment::acrossBranches()
            ->where('order_id', $return->order_id)
            ->where('status', PaymentTransactionStatus::Success->value)
            ->with('paymentMethod')
            ->latestFirst()
            ->get()
            ->map(fn (Payment $payment): array => PaymentPresenter::row($payment))
            ->values()
            ->all();
    }

    /**
     * What the refund form starts at.
     *
     * The returned goods' value, plus the delivery the customer paid when the
     * shop was at fault (`ReturnReason::faultOfShop()`) — a parcel that
     * arrived broken was never delivered properly, while a change of mind was.
     * Capped at what the order has left to give back, so the suggestion is
     * never a figure the server would refuse.
     *
     * A suggestion, not a rule: a staff member may change it, and
     * `ProcessRefund` re-checks the ceiling under the order's row lock.
     */
    private function suggestedRefund(OrderReturn $return): int
    {
        $goods = $return->goodsValue();
        $shipping = $return->reason->faultOfShop() && $this->coversWholeOrder($return)
            ? $return->order->shipping_cost
            : 0;

        return min($goods + $shipping, $return->order->refundableAmount());
    }

    /**
     * Whether this request sends back everything the order contained.
     *
     * The delivery fee only goes back with a *whole* order: half a parcel
     * returned was still half a parcel delivered, and refunding the carriage
     * twice over two requests would pay it back twice.
     */
    private function coversWholeOrder(OrderReturn $return): bool
    {
        $ordered = (int) $return->order->items()->sum('quantity');

        return $ordered > 0 && $return->totalQuantity() >= $ordered;
    }

    /**
     * The branches a central actor may filter by.
     *
     * Read without the branch scope because this list is for a central actor
     * by definition; a branch account never receives it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return Branch::withoutGlobalScopes()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => $branch->name,
            ])
            ->values()
            ->all();
    }
}
