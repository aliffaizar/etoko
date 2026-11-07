<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
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
 *
 * The order is re-read under a row lock first. Two staff pressing "Proses"
 * at the same moment then queue: the second finds the order already past that
 * point and is refused, instead of writing the same transition twice.
 *
 * Customer-facing notifications are deliberately not here yet — the storefront
 * has no notification feed until Phase 2. This is the one place they will hook
 * into when it does.
 */
final class TransitionOrderStatus
{
    public function __construct(
        private readonly CommitReservation $commitReservation,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    /**
     * Move an order to a new status, with everything that implies.
     *
     * @param  string|null  $note  Shown on the timeline; the reason, in the actor's words.
     *
     * @throws IllegalOrderTransitionException
     */
    public function handle(
        Order $order,
        OrderStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        return DB::transaction(function () use ($order, $to, $actor, $note): Order {
            $locked = $this->lock($order);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalOrderTransitionException::for($locked, $from, $to);
            }

            $this->moveStock($locked, $to);

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
     */
    private function moveStock(Order $order, OrderStatus $to): void
    {
        if ($to === OrderStatus::Paid) {
            $this->commitReservation->handleForOrder($order);

            return;
        }

        if ($to->releasesReservedStock()) {
            $this->releaseReservation->handleForOrder(
                $order,
                "Pesanan {$order->order_number} {$this->releaseWording($to)}.",
            );
        }
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
            $changes['payment_status'] = PaymentStatus::Paid;
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

    private function releaseWording(OrderStatus $to): string
    {
        return $to === OrderStatus::Expired ? 'kedaluwarsa' : 'dibatalkan';
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the queue worker that expires an unpaid
     * order and the payment callback that marks one paid both run with no
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
