<?php

namespace App\Actions\Order;

use App\Actions\Payment\ExpirePayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ReservationMissingException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Notifications\OrderReadyForPickupNotification;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order's status changes (P1-E7-T7).
 *
 * Four things happen together or not at all, which is why they live in one
 * action rather than in each screen that moves an order:
 *
 * 1. **The move is checked** against `OrderStatus::allowedTransitions()`. An
 *    illegal edge throws rather than being written; the state machine in
 *    `docs/03-data-model.md` §6 is the definition and the enum is its code.
 * 2. **Stock follows the status.** Reaching `paid` turns the order's holds into
 *    a sale (`CommitReservation`); reaching `cancelled` or `expired` gives them
 *    back (`ReleaseReservation`). Writing `status` by hand would leave stock
 *    held for an order nobody is waiting for, or sold twice.
 * 3. **The timeline gets a row** in `order_status_histories`, append-only, so
 *    "when did this get paid, and who said so?" always has an answer.
 * 4. **The matching timestamp is stamped** on the order, which is only a
 *    denormalisation of the row above so listings can sort by it.
 * 5. **Open payments follow the status too** (P2-E1-T3). An order that
 *    expires or is cancelled must not leave a live ask for money behind it:
 *    its stock has gone back on sale, so a customer finishing a transfer
 *    afterwards would be paying for goods that are no longer theirs.
 *    `ExpirePayment::forOrder()` closes the attempts and deliberately leaves
 *    `payment_status` to this action, so a *cancelled* order does not read as
 *    *expired*.
 *
 * The order is re-read under a row lock first. Two staff pressing "Proses"
 * at the same moment then queue: the second finds the order already past that
 * point and is refused, instead of writing the same transition twice.
 *
 * 6. **Click & Collect issues its code** (P2-E2-T7). Reaching
 *    `ready_for_pickup` writes `orders.pickup_code` and tells the customer
 *    their goods are waiting. Both belong here rather than in the screen that
 *    pressed the button: a code written outside this transaction could exist
 *    for an order whose transition rolled back, and a customer who was never
 *    told is the whole failure mode of Click & Collect.
 *
 * The notification is queued and `afterCommit`, so a worker never looks for a
 * status this transaction has not finished writing. The **rest** of the order
 * notifications — placed, paid, shipped, completed, cancelled — are `P2-E6-T3`
 * and will hook in exactly here.
 */
final class TransitionOrderStatus
{
    /**
     * How many stock holds the most recent `handle()` call gave back.
     *
     * Read by the screens that cancel an order, so the message they show is
     * built from what happened rather than from what usually happens (MED-03):
     * cancelling an unpaid order releases its holds, cancelling a paid one
     * releases nothing because the goods are already off the shelf, and
     * telling staff their stock came back when it did not is how goods go
     * missing on paper. Written on every call, so it always describes the
     * transition the caller just asked for and nothing earlier.
     */
    public private(set) int $releasedHolds = 0;

    /**
     * How many open payment attempts the most recent `handle()` call closed.
     *
     * Written on every call, like `$releasedHolds`, so a caller can say
     * something true about what it just did rather than something that is
     * usually true.
     */
    public private(set) int $closedPayments = 0;

    public function __construct(
        private readonly CommitReservation $commitReservation,
        private readonly ReleaseReservation $releaseReservation,
        private readonly ExpirePayment $expirePayment,
    ) {}

    /**
     * Move an order to a new status, with everything that implies.
     *
     * @param  string|null  $note  Shown on the timeline; the reason, in the actor's words.
     *
     * @throws IllegalOrderTransitionException when the move is not a legal edge of the state machine.
     * @throws InsufficientStockException when reaching `paid` finds the branch's shelf short.
     * @throws ReservationMissingException when reaching `paid` finds no hold left to commit.
     */
    public function handle(
        Order $order,
        OrderStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        $this->releasedHolds = 0;
        $this->closedPayments = 0;

        return DB::transaction(function () use ($order, $to, $actor, $note): Order {
            $locked = $this->lock($order);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalOrderTransitionException::for($locked, $from, $to);
            }

            $this->releasedHolds = $this->moveStock($locked, $to);
            $this->closedPayments = $to->releasesReservedStock()
                ? $this->expirePayment->forOrder($locked, $this->paymentClosureReason($to, $note))
                : 0;

            $locked->fill(['status' => $to] + $this->sideEffectsOf($locked, $to, $note));
            $locked->save();

            OrderStatusHistory::create([
                'order_id' => $locked->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            $this->announce($locked, $to);

            return $locked;
        });
    }

    /**
     * Record the order coming into existence (P1-E7-T5).
     *
     * The first row of a timeline has no status to come from, so it is not a
     * transition and does not go through the checks above. `PlaceOrder` calls
     * this inside its own transaction.
     */
    public function recordInitial(Order $order, ?User $actor = null, ?string $note = null): OrderStatusHistory
    {
        return OrderStatusHistory::create([
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => $order->status,
            'note' => $note,
            'changed_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * Take the order's stock where its new status says it should be.
     *
     * Returns how many holds were given back to the shelf, which is zero for
     * every move that is not a release **and** for a release that found
     * nothing left to release — an order already paid for holds no stock, it
     * holds goods that have left the shelf, and cancelling it does not put
     * them back. Callers need to tell those apart to say something true about
     * it (MED-03).
     */
    private function moveStock(Order $order, OrderStatus $to): int
    {
        if ($to === OrderStatus::Paid) {
            $this->commitReservation->handleForOrder($order);

            return 0;
        }

        if ($to->releasesReservedStock()) {
            return $this->releaseReservation->handleForOrder(
                $order,
                "Pesanan {$order->order_number} {$this->releaseWording($to)}.",
            );
        }

        return 0;
    }

    /**
     * The columns that change alongside `status`.
     *
     * @return array<string, mixed>
     */
    private function sideEffectsOf(Order $order, OrderStatus $to, ?string $note): array
    {
        $changes = [];
        $column = Order::timestampColumnFor($to);

        if ($column !== null && $order->getAttribute($column) === null) {
            $changes[$column] = now();
        }

        if ($to === OrderStatus::Paid) {
            // A COD order reaches `paid` because its goods are going out, not
            // because its money came in: the courier has not been anywhere yet
            // (ADR-040). `VerifyPayment` moves this to `Paid` when the cash is
            // recorded. Read from the order's own payment attempt rather than
            // passed in, so every caller of this action — checkout, the panel,
            // the status check — gets the same answer.
            $changes['payment_status'] = $order->requiresCashOnDelivery()
                ? PaymentStatus::Pending
                : PaymentStatus::Paid;
        }

        if ($to === OrderStatus::ReadyForPickup && $order->pickup_code === null) {
            // Issued here and nowhere else (P2-E2-T7). The null check is
            // belt-and-braces — the state machine has no way back into
            // `ready_for_pickup` — because the day it grows one, regenerating
            // the code would invalidate the one the customer is already
            // holding on their phone.
            $changes['pickup_code'] = Order::newPickupCode();
        }

        if ($to === OrderStatus::Expired) {
            $changes['payment_status'] = PaymentStatus::Expired;
            $changes['cancel_reason'] = $note ?? 'Batas waktu pembayaran terlewat.';
        }

        if ($to === OrderStatus::Cancelled) {
            $changes['cancel_reason'] = $note ?? 'Dibatalkan.';
        }

        if ($to === OrderStatus::Refunded) {
            $changes['payment_status'] = PaymentStatus::Refunded;
        }

        if ($to === OrderStatus::PartiallyRefunded) {
            $changes['payment_status'] = PaymentStatus::PartiallyRefunded;
        }

        return $changes;
    }

    /**
     * Tell the customer, when the new status is one they are waiting on
     * (P2-E2-T7).
     *
     * Only Click & Collect for now, because it is the one status whose whole
     * point is that somebody has to come and fetch something. A guest order
     * (POS, Phase 3) has no account to notify, and the counter has the
     * customer standing in front of it anyway.
     */
    private function announce(Order $order, OrderStatus $to): void
    {
        if ($to !== OrderStatus::ReadyForPickup) {
            return;
        }

        $customer = $order->user;

        if (! $customer instanceof User) {
            return;
        }

        $customer->notify(new OrderReadyForPickupNotification($order));
    }

    /**
     * What to write on an open payment attempt this transition closes.
     *
     * The customer reads it, so it says why the ask for money went away: an
     * attempt closed because the order was cancelled did not run out of time.
     */
    private function paymentClosureReason(OrderStatus $to, ?string $note): string
    {
        if ($to === OrderStatus::Expired) {
            return ExpirePayment::DEADLINE_PASSED;
        }

        return 'Pesanan dibatalkan'.($note === null ? '.' : ": {$note}");
    }

    private function releaseWording(OrderStatus $to): string
    {
        return $to === OrderStatus::Expired ? 'kedaluwarsa' : 'dibatalkan';
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the queue worker that expires an unpaid
     * order and the payment status check that marks one paid both run with no
     * branch context to narrow by, and the key already identifies the row.
     */
    private function lock(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
