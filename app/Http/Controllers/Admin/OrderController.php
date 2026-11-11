<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Order\AssignOrderBranch;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Shipping\QuoteShipping;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ReservationMissingException;
use App\Exceptions\ShippingFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignOrderBranchRequest;
use App\Http\Requests\Admin\IndexOrderRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Requests\Shop\CancelOrderRequest;
use App\Models\Branch;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipping\Data\ShippingQuoteRequest;
use App\Support\OrderListQuery;
use App\Support\OrderPresenter;
use App\Support\PaymentPresenter;
use App\Support\ShipmentPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order queue, for central and branch staff alike (P1-E7-T8, T9, T10).
 *
 * One screen for both, narrowed rather than duplicated (ADR-016): `Order`
 * carries `BelongsToBranch`, so a branch account's listing is already its own
 * branch's orders and the branch filter is simply of no use to them. Central
 * sees every branch and uses that filter to look at one.
 *
 * Unlike the inventory screens, the branch here is a **filter** and not a place
 * the screen stands in: there is no `BranchWorkspace`, because "every open
 * order in the company" is a question central genuinely asks, while "the stock
 * of every warehouse at once" is not.
 *
 * Because `SubstituteBindings` runs after `SetBranchContext` (ADR-027),
 * another branch's order id in the URL is **not found** rather than refused —
 * the 404 is what keeps a branch from learning that an order exists at all.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly QuoteShipping $quoteShipping) {}

    public function index(IndexOrderRequest $request): Response
    {
        $this->authorize('viewAny', Order::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->filters();

        return Inertia::render('admin/orders/index', [
            'orders' => $this->paginated($filters),
            'filters' => $filters,
            'summary' => $this->summary(),
            'options' => [
                'statuses' => OrderStatus::options(),
                'payment_statuses' => PaymentStatus::options(),
                'fulfillment_types' => array_map(
                    fn (FulfillmentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                    FulfillmentType::cases(),
                ),
                // Branch accounts get no branch select: theirs is the only one
                // the listing can contain, so offering it would be a control
                // that cannot change anything.
                'branches' => $actor->isCentral() ? $this->branchOptions() : [],
            ],
            'is_central' => $actor->isCentral(),
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorize('view', $order);

        /** @var User $actor */
        $actor = request()->user();

        $order->load([
            'branch:id,name,city_name,address_line,phone',
            'user:id,name,email',
            'createdBy:id,name',
            'items.product:id,slug',
            'statusHistories.changedBy:id,name',
            'stockReservations' => fn ($q) => $q->with(['warehouse:id,name', 'variant:id,sku'])->orderBy('id'),
            'payments' => fn ($q) => $q->with(['paymentMethod', 'verifiedBy:id,name']),
            // The order's dispatches, newest first, each with the timeline the
            // customer is reading (P2-E2-T4). `courier` is loaded because the
            // card offers the courier's own tracking link and asks whether the
            // parcel can be tracked automatically — both read the live row,
            // not the snapshot.
            'shipments' => fn ($q) => $q->with(['trackingEvents', 'courier', 'dispatchedBy:id,name']),
        ]);

        return Inertia::render('admin/orders/show', [
            'order' => OrderPresenter::detail($order, [
                'update_status' => $actor->can('updateStatus', $order),
                'cancel' => $actor->can('cancel', $order),
                'assign_branch' => $actor->can('assignBranch', $order),
                'dispatch_shipment' => $actor->can('dispatchShipment', $order),
                'confirm_pickup' => $actor->can('confirmPickup', $order),
            ]),
            'reservations' => OrderPresenter::reservations($order->stockReservations),
            'blocked' => [
                'cancel' => $order->cancellationBlockedReason(asCustomer: false),
                'assign_branch' => $order->branchReassignmentBlockedReason(),
                // The same sentences the endpoints refuse with, so the screen
                // explains a disabled form rather than hiding it (P2-E2-T4, T7).
                'dispatch' => $order->dispatchBlockedReason(),
                'confirm_pickup' => $order->pickupConfirmationBlockedReason(),
            ],
            // Only the moves this order can actually make, so the screen offers
            // nothing the state machine would refuse.
            'next_statuses' => array_map(
                fn (OrderStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                $order->status->allowedTransitions(),
            ),
            'branches' => $actor->can('assignBranch', $order) ? $this->branchOptions($order->branch_id) : [],
            // The order's payment attempts, newest first, so "has this been
            // paid for, how, and who said so" is answered on the screen that
            // asks it rather than on a second one (P2-E1-T4). Each row carries
            // its own `can.settle`, because whether an attempt may still be
            // decided depends on the attempt and not only on the actor.
            'payments' => $actor->can('payments.view')
                ? $order->payments
                    ->map(fn (Payment $payment): array => PaymentPresenter::detail($payment, [
                        'settle' => $actor->can('settle', $payment),
                    ]))
                    ->values()
                    ->all()
                : [],
            // How the goods are going out, and how far they have got
            // (P2-E2-T4, T5). Each dispatch carries its own `can.manage`,
            // because a parcel that has already been delivered may be read by
            // somebody who may no longer move it.
            'shipments' => $actor->can('shipments.view')
                ? $order->shipments
                    ->map(fn (Shipment $shipment): array => ShipmentPresenter::detail($shipment, [
                        'manage' => $actor->can('manage', $shipment),
                    ]))
                    ->values()
                    ->all()
                : [],
            // The couriers a packer may choose from, priced where the rate
            // table reaches this address. Offered only while the order is
            // actually dispatchable, so the screen does not draw a form the
            // server would refuse.
            'courier_services' => $actor->can('dispatchShipment', $order)
                ? $this->courierServiceChoices($order)
                : [],
            'shipment_statuses' => array_map(
                fn (ShipmentStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                ShipmentStatus::manualTransitions(),
            ),
        ]);
    }

    /**
     * The couriers the dispatch form offers, with a price where there is one
     * (P2-E2-T4).
     *
     * Quoted against the order's own address and weight, through the same
     * action the checkout screen used, so the figure beside a service is the
     * one the shop would have charged for it. A quote that fails outright —
     * every provider down, or no rule reaching the address — is **not** an
     * error here: the services are still listed, without prices, because the
     * goods are in somebody's hands and have to go out either way.
     *
     * @return array<int, array<string, mixed>>
     */
    private function courierServiceChoices(Order $order): array
    {
        $services = CourierService::query()
            ->with('courier')
            ->active()
            ->whereHas('courier', fn ($query) => $query->where('is_active', true))
            ->ordered()
            ->get();

        if ($services->isEmpty()) {
            return [];
        }

        try {
            $quotes = $this->quoteShipping->handle(ShippingQuoteRequest::forOrder($order));
        } catch (ShippingFailedException) {
            $quotes = [];
        }

        return ShipmentPresenter::serviceChoices($services, $quotes);
    }

    /**
     * Move the order along its state machine (P1-E7-T8).
     */
    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        TransitionOrderStatus $transition,
    ): RedirectResponse {
        $this->authorize('updateStatus', $order);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $moved = $transition->handle($order, $request->status(), $actor, $request->note());
        } catch (IllegalOrderTransitionException $refused) {
            return back()->withErrors(['status' => $refused->getMessage()]);
        } catch (InsufficientStockException $short) {
            // Reaching `paid` takes the goods off the shelf, and the shelf can
            // be short if stock was issued out while the order waited.
            return back()->withErrors(['status' => $short->getMessage()]);
        } catch (ReservationMissingException $noHold) {
            // The order's stock was given back while it was still payable, so
            // there is nothing to take off the shelf and marking it paid would
            // sell goods the ledger never moved (HIGH-03). Said in words rather
            // than as a 500: the order stays where it is until somebody looks.
            return back()->withErrors(['status' => $noHold->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$moved->order_number} kini berstatus \"{$moved->status->label()}\".",
        ]);

        return back();
    }

    /**
     * Cancel the order (P1-E7-T9).
     *
     * An admin may cancel at any point the state machine allows, which is
     * wider than what a customer may do — but still not "whenever": a shipped
     * order is cancelled by the courier bringing it back, not by a button.
     * Money already taken is refunded separately (`payments.refund`, Phase 2).
     */
    public function cancel(
        CancelOrderRequest $request,
        Order $order,
        TransitionOrderStatus $transition,
    ): RedirectResponse {
        $this->authorize('cancel', $order);

        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->reason() ?? 'Dibatalkan oleh staf.';

        try {
            $transition->handle($order, OrderStatus::Cancelled, $actor, $reason);
        } catch (IllegalOrderTransitionException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} dibatalkan. "
                .$this->cancellationStockMessage($transition->releasedHolds),
        ]);

        return back();
    }

    /**
     * What to tell the actor about the cancelled order's stock.
     *
     * Built from what the transition actually did (MED-03). An unpaid order's
     * holds go back on the shelf; a paid order's goods have already left it,
     * and `ReleaseReservation` correctly leaves committed holds alone — so the
     * old wording, which always claimed the stock was released, told staff
     * that goods were back when they were not. Phase 1 has no restock path for
     * a cancelled sale at all (ADR-035), and the sentence says so rather than
     * leaving somebody to find out from a stock count.
     */
    private function cancellationStockMessage(int $releasedHolds): string
    {
        return $releasedHolds > 0
            ? 'Stok yang ditahan sudah dilepas.'
            : 'Stok tidak dikembalikan otomatis; barang yang sudah keluar dibalik lewat dokumen retur.';
    }

    /**
     * Hand the order to another branch (P1-E7-T10).
     *
     * Central only — `OrderPolicy::assignBranch` requires a central actor
     * explicitly, not merely the permission, so granting
     * `orders.assign_branch` on the Role screen cannot open it to a branch.
     */
    public function assignBranch(
        AssignOrderBranchRequest $request,
        Order $order,
        AssignOrderBranch $assignBranch,
    ): RedirectResponse {
        $this->authorize('assignBranch', $order);

        /** @var User $actor */
        $actor = $request->user();
        $destination = Branch::withoutGlobalScopes()->findOrFail($request->integer('branch_id'));

        if ($destination->getKey() === $order->branch_id) {
            return back()->withErrors(['branch_id' => 'Pesanan sudah diproses oleh cabang itu.']);
        }

        try {
            $assignBranch->handle($order, $destination, $actor, $request->string('note')->toString() ?: null);
        } catch (CheckoutFailedException $refused) {
            return back()->withErrors(['branch_id' => $refused->getMessage()]);
        } catch (InsufficientStockException $short) {
            return back()->withErrors([
                'branch_id' => "Cabang {$destination->name} tidak memiliki stok yang cukup untuk pesanan ini. "
                    .$short->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} dipindahkan ke cabang {$destination->name}.",
        ]);

        return to_route('admin.orders.show', $order);
    }

    /**
     * @param  array{search: string, status: string, payment_status: string, fulfillment_type: string, branch: string, from: string, until: string, sort: string, direction: string}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(array $filters): LengthAwarePaginator
    {
        return OrderListQuery::from($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order): array => OrderPresenter::row($order));
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
        $byStatus = Order::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending_payment' => (int) ($byStatus[OrderStatus::PendingPayment->value] ?? 0),
            'paid' => (int) ($byStatus[OrderStatus::Paid->value] ?? 0),
            'processing' => (int) ($byStatus[OrderStatus::Processing->value] ?? 0),
            'ready_for_pickup' => (int) ($byStatus[OrderStatus::ReadyForPickup->value] ?? 0),
            'shipped' => (int) ($byStatus[OrderStatus::Shipped->value] ?? 0),
            'open' => Order::query()->open()->count(),
        ];
    }

    /**
     * The branches a central actor may filter by or assign to.
     *
     * Read without the branch scope because this list is for a central actor
     * by definition; a branch account never receives it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(?int $excludeId = null): array
    {
        return Branch::withoutGlobalScopes()
            ->active()
            ->ordered()
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => $branch->name,
            ])
            ->values()
            ->all();
    }
}
