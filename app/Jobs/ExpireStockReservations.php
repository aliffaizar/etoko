<?php

namespace App\Jobs;

use App\Actions\Order\ReleaseReservation;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Models\StockReservation;
use App\Scopes\BranchScope;
use Illuminate\Contracts\Queue\ShouldQueue;
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
    ): void {
        $moment = $this->asOf ?? Carbon::now();

        $this->expireOrders($transitionOrderStatus, $moment);
        $this->releaseOrphanHolds($releaseReservation, $moment);
    }

    /**
     * Expire the orders whose holds have run out, each on its own.
     *
     * One order failing — someone paid for it between the query and the
     * transition, so it is no longer in `pending_payment` — must not stop the
     * rest of the batch, which is why the loop catches rather than letting the
     * job fail and retry the whole sweep.
     */
    private function expireOrders(TransitionOrderStatus $transitionOrderStatus, Carbon $moment): void
    {
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
     * Release expired holds that belong to no order.
     *
     * Also catches holds left behind by an order that moved out of
     * `pending_payment` without settling them, which should not happen — but
     * a hold nothing will ever release is exactly the leak this job exists to
     * prevent, so it is swept rather than trusted.
     */
    private function releaseOrphanHolds(ReleaseReservation $releaseReservation, Carbon $moment): void
    {
        $ids = StockReservation::withoutGlobalScope(BranchScope::class)
            ->expired($moment)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        foreach ($ids as $id) {
            $releaseReservation->handle($id, 'Penahanan stok kedaluwarsa dan dilepas otomatis.');
        }
    }
}
