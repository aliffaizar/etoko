<?php

namespace App\Actions\Order;

use App\Enums\BranchStatus;
use App\Exceptions\CheckoutFailedException;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Moves an order to another branch to fulfil (P1-E7-T10, `orders.assign_branch`).
 *
 * Central only: deciding which branch serves a customer is a company
 * decision, and a branch admin who could push work to a neighbour could also
 * take it from one (BR-1's reasoning, enforced in `OrderPolicy`).
 *
 * Moving an order is **moving the stock it is holding**, which is the whole
 * difficulty. Three things happen in one transaction:
 *
 * 1. The destination's stock is held — first, so a destination that cannot
 *    cover the order fails before anything is given up.
 * 2. The origin's holds are released.
 * 3. `branch_id` moves, and the timeline records where it went.
 *
 * Doing it in that order matters: releasing first would briefly put the goods
 * back on sale at the origin, and if the destination then came up short the
 * order would be left holding nothing at all.
 *
 * An order whose stock has already left the shelf cannot be moved — see
 * `Order::branchReassignmentBlockedReason()`, which allows `pending_payment`
 * and nothing else (ADR-036). Once an order is paid for, the branch that
 * issued the goods is a fact rather than an assignment, and bringing them to
 * another branch is a stock transfer document (`P2-E3`).
 */
final class AssignOrderBranch
{
    public function __construct(
        private readonly ReserveStock $reserveStock,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    /**
     * @throws CheckoutFailedException When the order or the destination will not take the move.
     * @throws InsufficientStockException When the destination branch cannot cover the order.
     */
    public function handle(Order $order, Branch $destination, ?User $actor = null, ?string $note = null): Order
    {
        if ($order->branch_id === $destination->getKey()) {
            throw new LogicException('The order is already at that branch.');
        }

        if ($reason = $order->branchReassignmentBlockedReason()) {
            throw new CheckoutFailedException($reason);
        }

        if ($destination->status !== BranchStatus::Active) {
            throw CheckoutFailedException::branchNotTrading($destination);
        }

        return DB::transaction(function () use ($order, $destination, $actor, $note): Order {
            $origin = $order->branch;
            $locked = $this->lock($order);

            // Asked again under the lock, not merely before the transaction:
            // the order may have been paid for in between, and a paid order's
            // goods are off the shelf rather than held, so there would be no
            // hold to move and `branch_id` would travel on its own (MED-04).
            if ($reason = $locked->branchReassignmentBlockedReason()) {
                throw new CheckoutFailedException($reason);
            }

            // Captured *before* the destination's holds exist, so releasing the
            // origin's cannot reach the ones about to be taken.
            $originHolds = $this->activeHoldIds($locked);
            $expiresAt = $this->expiryOf($originHolds);

            $locked->setRelation('branch', $destination);
            $locked->branch_id = $destination->getKey();

            $lines = $this->linesOf($locked);

            // Every line's variant has been hard-deleted from the catalogue, so
            // there is nothing to reserve anywhere. `ReserveStock` answers an
            // empty list with a `LogicException` — a 500 — and this is a
            // refusal somebody typed into a form, so it is said in words
            // instead (LOW-08).
            if ($lines === []) {
                throw new CheckoutFailedException(
                    "Pesanan {$locked->order_number} tidak punya produk yang masih ada di katalog, "
                        .'jadi cabangnya tidak dapat dipindahkan.',
                );
            }

            // Destination first: if it cannot cover the order, nothing has
            // been given up and the transaction rolls back untouched. This is
            // the destination-stock validation `P1-E7-T10` asks for, and it is
            // only sound because the status above guarantees there is a hold to
            // move in the first place.
            $this->reserveStock->handle($locked, $lines, $expiresAt);

            foreach ($originHolds as $id) {
                $this->releaseReservation->handle(
                    $id,
                    "Pesanan {$locked->order_number} dipindahkan ke cabang {$destination->name}.",
                );
            }

            $locked->save();

            OrderStatusHistory::create([
                'order_id' => $locked->getKey(),
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'note' => $note ?? "Cabang pemroses dipindahkan dari {$origin->name} ke {$destination->name}.",
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * The ids of the holds the order is carrying right now.
     *
     * Ids rather than models: each one is re-read under its own lock when it is
     * released, so carrying a stale copy forward would be pointless.
     *
     * @return array<int, int>
     */
    private function activeHoldIds(Order $order): array
    {
        return $order->stockReservations()
            ->withoutGlobalScope(BranchScope::class)
            ->active()
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * When the moved holds should expire: the same moment the originals did.
     *
     * A move must not quietly extend a customer's payment window, nor cut it
     * short. Holds with no expiry (an order already past payment) keep none.
     *
     * @param  array<int, int>  $holdIds
     */
    private function expiryOf(array $holdIds): ?Carbon
    {
        if ($holdIds === []) {
            return null;
        }

        $latest = StockReservation::withoutGlobalScope(BranchScope::class)
            ->whereKey($holdIds)
            ->max('expires_at');

        return $latest === null ? null : Carbon::parse($latest);
    }

    /**
     * The order's lines as `ReserveStock` wants them.
     *
     * Lines whose variant has since been deleted from the catalogue hold no
     * stock and are skipped — there is no `stocks` row to reserve against, and
     * the snapshot on the line is what the customer is owed either way.
     *
     * @return array<int, array{variant: ProductVariant, quantity: int}>
     */
    private function linesOf(Order $order): array
    {
        $order->loadMissing('items.variant');

        return $order->items
            ->filter(fn (OrderItem $item): bool => $item->variant instanceof ProductVariant)
            ->map(fn (OrderItem $item): array => [
                'variant' => $item->variant,
                'quantity' => $item->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * Read across branches: this runs for a central actor acting on an order
     * that belongs to one branch and is about to belong to another.
     */
    private function lock(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
