<?php

namespace App\Jobs;

use App\Actions\Order\ReleaseReservation;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\ExpirePayment;
use App\Actions\Payment\ReconcilePayment;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use App\Scopes\BranchScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Gives back stock nobody finished paying for (P1-E7-T3, BR-6).
 *
 * A hold taken at checkout keeps units off the shelf. Without this sweep, one
 * abandoned basket would hold them for ever: `stocks.reserved_quantity` would
 * climb and never come down, and a branch with stock on the shelf would
 * gradually stop being able to sell it.
 *
 * Runs every minute from `routes/console.php`. A minute is fine because the
 * hold's own `expires_at` is what decides — the sweep only has to be *more
 * frequent than it matters*, not exact.
 *
 * Two kinds of hold are swept, and they are not the same thing:
 *
 * - **A hold with an order.** The order itself has run out of time, so it is
 *   moved to `expired` through `TransitionOrderStatus`, which releases the
 *   holds as part of the move. Releasing the stock and leaving the order
 *   sitting in `pending_payment` would show a customer an order they can still
 *   pay for whose goods have already gone back on sale.
 * - **A hold with no order** (a timed cart, Phase 2). There is nothing to
 *   transition, so it is released directly.
 * - **A hold left behind** by an order that moved out of `pending_payment`
 *   without settling it. That should not happen, so it is released and
 *   logged — and, since the sweep is batched, it is a query that names the
 *   statuses it means rather than one that could reach a hold whose order is
 *   still waiting to be paid (HIGH-03).
 *
 * The job also closes **overdue payment attempts** (`P2-E1-T3`). They are
 * swept here rather than by a job of their own because an attempt's deadline
 * *is* its order's stock hold (ADR-041): one clock swept by one run cannot
 * leave an order whose goods are back on sale still showing a payable virtual
 * account, or an order whose payment has lapsed still holding its units.
 *
 * **Nothing is expired without asking** (ADR-052, ADR-053). Before an order
 * is expired or an attempt closed, its provider is asked whether the money
 * arrived: there is no webhook, so what the database says is only as fresh as
 * the last time somebody asked, and expiring a paid order gives its goods to
 * somebody else.
 * A paid attempt settles the order instead. An attempt whose provider cannot
 * be reached is left for the next run, for at most `GATEWAY_GRACE_MINUTES`
 * past its deadline — an outage must not hold stock for ever either.
 *
 * `WithoutOverlapping` keeps two runs apart. The actions are each safe to run
 * twice — they re-read every row under its lock and skip holds already settled
 * — but two sweeps racing would spend their time losing to each other's locks.
 */
class ExpireStockReservations implements ShouldQueue
{
    use Queueable;

    /**
     * How many orders one run will expire.
     *
     * A bound rather than "all of them": a backlog is worked off over several
     * minutes instead of one run holding locks across thousands of rows while
     * live checkouts queue behind it.
     */
    public const BATCH_SIZE = 200;

    /**
     * How long past its deadline an attempt is kept open while its provider
     * cannot be asked about it.
     */
    public const GATEWAY_GRACE_MINUTES = 30;

    /**
     * Whether each attempt's provider could be asked during this run, by
     * payment id, so no attempt is asked about twice in one sweep.
     *
     * @var array<int, bool>
     */
    private array $providerAnswered = [];

    public function __construct(public readonly ?Carbon $asOf = null) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('expire-stock-reservations'))->dontRelease()];
    }

    public function handle(
        TransitionOrderStatus $transitionOrderStatus,
        ReleaseReservation $releaseReservation,
        ExpirePayment $expirePayment,
        ReconcilePayment $reconcilePayment,
    ): void {
        $moment = $this->asOf ?? Carbon::now();
        $this->providerAnswered = [];

        // Orders first, because expiring one closes its own payment attempt as
        // part of the transition; whatever the sweep below finds afterwards is
        // an attempt whose order is no longer waiting on it.
        $this->expireOrders($transitionOrderStatus, $reconcilePayment, $moment);
        $this->releaseOrphanHolds($releaseReservation, $moment);
        $this->releaseStrandedHolds($releaseReservation, $moment);
        $expirePayment->sweep($moment, except: $this->overdueAttemptsToKeepOpen($reconcilePayment, $moment));
    }

    /**
     * Expire the orders whose holds have run out, each on its own.
     *
     * One order failing — someone paid for it between the query and the
     * transition, so it is no longer in `pending_payment` — must not stop the
     * rest of the batch, which is why the loop catches rather than letting the
     * job fail and retry the whole sweep.
     */
    private function expireOrders(
        TransitionOrderStatus $transitionOrderStatus,
        ReconcilePayment $reconcilePayment,
        Carbon $moment,
    ): void {
        $orderIds = StockReservation::withoutGlobalScope(BranchScope::class)
            ->expired($moment)
            ->whereNotNull('order_id')
            ->distinct()
            ->orderBy('order_id')
            ->limit(self::BATCH_SIZE)
            ->pluck('order_id');

        $orders = Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($orderIds)
            ->where('status', OrderStatus::PendingPayment)
            ->get();

        foreach ($orders as $order) {
            if ($this->paidOrUnknown($order, $reconcilePayment, $moment)) {
                continue;
            }

            try {
                $transitionOrderStatus->handle(
                    $order,
                    OrderStatus::Expired,
                    note: 'Batas waktu pembayaran terlewat.',
                );
            } catch (IllegalOrderTransitionException $alreadyMoved) {
                // The order was paid for or cancelled between the query and
                // here. Its holds were settled by that move, so there is
                // nothing left for this sweep to do with it.
                Log::info('Skipped expiring an order that had already moved on.', [
                    'order_id' => $order->getKey(),
                    'status' => $alreadyMoved->from->value,
                ]);
            }
        }
    }

    /**
     * Whether an order due to expire must be left alone after asking its
     * attempts' providers: it turned out to be paid, or nobody could say.
     */
    private function paidOrUnknown(Order $order, ReconcilePayment $reconcilePayment, Carbon $moment): bool
    {
        $unknown = false;

        foreach ($order->payments()->open()->with('paymentMethod')->get() as $payment) {
            $unknown = $this->mustStayOpen($payment, $reconcilePayment, $moment) || $unknown;
        }

        if ($unknown) {
            return true;
        }

        return $order->refresh()->status !== OrderStatus::PendingPayment;
    }

    /**
     * The overdue attempts the payment sweep must not close this run.
     *
     * Asking is what settles a paid attempt, so by the time this returns the
     * only overdue attempts still open are the unpaid ones — which the sweep
     * closes — and the ones named here, whose provider did not answer.
     *
     * @return array<int, int>
     */
    private function overdueAttemptsToKeepOpen(ReconcilePayment $reconcilePayment, Carbon $moment): array
    {
        return Payment::withoutGlobalScope(BranchScope::class)
            ->overrun($moment)
            ->with('paymentMethod')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(ExpirePayment::BATCH_SIZE)
            ->get()
            ->filter(fn (Payment $payment): bool => $this->mustStayOpen($payment, $reconcilePayment, $moment))
            ->map(fn (Payment $payment): int => (int) $payment->getKey())
            ->values()
            ->all();
    }

    /**
     * Ask an attempt's provider about it, once per run, and say whether the
     * attempt has to be kept open because the provider could not answer.
     */
    private function mustStayOpen(Payment $payment, ReconcilePayment $reconcilePayment, Carbon $moment): bool
    {
        $answered = $this->providerAnswered[$payment->getKey()] ??= $reconcilePayment->handle($payment);

        if ($answered || $payment->expires_at === null) {
            return false;
        }

        return $moment->lessThan($payment->expires_at->copy()->addMinutes(self::GATEWAY_GRACE_MINUTES));
    }

    /**
     * Release expired holds that belong to no order at all.
     *
     * `whereNull('order_id')` is what makes this query match its name, and
     * leaving it out was HIGH-03: the previous version swept *every* expired
     * hold, and because `expireOrders()` above had already finished the first
     * `BATCH_SIZE` orders, this query picked up hold number 201 onwards —
     * holds belonging to orders still sitting in `pending_payment`, untouched.
     * Their stock went back on sale while the customer was still shown an
     * order they could pay for, and `CommitReservation` has nothing left to
     * commit when they do, so the goods would have been sold without ever
     * leaving the ledger. One sweep over 220 such orders left exactly 20 in
     * that state.
     *
     * A backlog is therefore worked off over several runs, in order: the
     * orders first, their holds with them, and only ownerless holds here.
     * That is what the class docblock promises, and now what the SQL says.
     */
    private function releaseOrphanHolds(ReleaseReservation $releaseReservation, Carbon $moment): void
    {
        $ids = StockReservation::withoutGlobalScope(BranchScope::class)
            ->expired($moment)
            ->whereNull('order_id')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        foreach ($ids as $id) {
            $releaseReservation->handle($id, 'Penahanan stok kedaluwarsa dan dilepas otomatis.');
        }
    }

    /**
     * Release expired holds whose order has already moved past
     * `pending_payment` without settling them.
     *
     * This should never find anything: every status that gives stock back goes
     * through `TransitionOrderStatus`, which releases the holds as part of the
     * move. But a hold nobody will ever release is the exact leak this job
     * exists to prevent, so the case is swept rather than trusted — and it is
     * a query of its own, naming the statuses it means, so that it can never
     * again reach a hold whose order is still waiting to be paid.
     *
     * It is logged because finding one means something upstream is wrong, and
     * a leak that is silently cleaned up is a leak nobody fixes.
     */
    private function releaseStrandedHolds(ReleaseReservation $releaseReservation, Carbon $moment): void
    {
        $ids = StockReservation::withoutGlobalScope(BranchScope::class)
            ->expired($moment)
            ->whereNotNull('order_id')
            ->whereHas(
                'order',
                fn (Builder $order) => $order
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('status', '!=', OrderStatus::PendingPayment),
            )
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        foreach ($ids as $id) {
            $released = $releaseReservation->handle(
                $id,
                'Penahanan stok tertinggal dan dilepas otomatis.',
            );

            if ($released) {
                Log::warning('Released a hold left behind by an order that had already moved on.', [
                    'stock_reservation_id' => $id,
                ]);
            }
        }
    }
}
