<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\CancelOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A customer's own orders (P1-E7-T6, T9).
 *
 * Scoped by `user_id`, not by policy. `OrderPolicy` is about staff: a shopper
 * holds no `orders.*` permission at all, and letting one through that policy
 * would mean deciding they may "view any order" — which is not what they may
 * do. Here the relationship *is* the authorisation, so another customer's
 * order is not found rather than refused, and nothing leaks about it existing.
 *
 * `Order` carries `BelongsToBranch`, but a customer is never branch-restricted
 * (`BranchContext` only narrows staff — ADR-028), so the scope adds nothing
 * and a customer sees every order they placed, whichever branch filled it.
 *
 * There is no separate success screen: the detail page shows the whole order
 * already, and `?baru=1` adds the confirmation panel to the top of it. Two
 * screens for one order would be two places to keep the totals right.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * The customer's order history.
     */
    public function index(Request $request): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        $status = (string) $request->string('status')->toString();
        $status = OrderStatus::tryFrom($status) === null ? '' : $status;

        return Inertia::render('shop/orders', [
            'orders' => $this->paginated($customer, $status),
            'filters' => ['status' => $status],
            'statuses' => OrderStatus::options(),
        ]);
    }

    /**
     * One order, with its lines and its timeline.
     */
    public function show(Request $request, Order $order): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        abort_unless($order->user_id === $customer->getKey(), 404);

        $order->load([
            'branch:id,name,city_name,address_line,phone',
            'items.product:id,slug',
            'statusHistories',
        ]);

        return Inertia::render('shop/order', [
            'order' => OrderPresenter::detail($order, [
                'cancel' => $order->cancellationBlockedReason(asCustomer: true) === null,
            ]),
            'cancel_blocked_reason' => $order->cancellationBlockedReason(asCustomer: true),
            // Set by the redirect from checkout, so the confirmation panel
            // appears once rather than every time the order is opened.
            'just_placed' => $request->boolean('baru'),
        ]);
    }

    /**
     * Call off an order that has not been paid for (P1-E7-T9).
     *
     * A customer may only do this while the order is still waiting for
     * payment: once the branch has begun picking it, calling it off is a
     * conversation rather than a button
     * (`Order::isCancellableByCustomer()`). The held stock goes back on sale
     * as part of the transition.
     */
    public function cancel(CancelOrderRequest $request, Order $order, TransitionOrderStatus $transition): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        abort_unless($order->user_id === $customer->getKey(), 404);

        $blocked = $order->cancellationBlockedReason(asCustomer: true);

        if ($blocked !== null) {
            return back()->withErrors(['reason' => $blocked]);
        }

        $reason = $request->reason() ?? 'Dibatalkan oleh pelanggan.';

        try {
            $transition->handle($order, OrderStatus::Cancelled, $customer, $reason);
        } catch (IllegalOrderTransitionException $moved) {
            return back()->withErrors(['reason' => $moved->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} dibatalkan."
                .($transition->releasedHolds > 0 ? ' Stok yang ditahan sudah dilepas.' : ''),
        ]);

        return back();
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginated(User $customer, string $status): LengthAwarePaginator
    {
        return $customer->orders()
            ->with(['branch:id,name'])
            ->withSum('items', 'quantity')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order): array => OrderPresenter::row($order));
    }
}
