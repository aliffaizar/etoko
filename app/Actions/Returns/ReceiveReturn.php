<?php

namespace App\Actions\Returns;

use App\Enums\ReservationStatus;
use App\Enums\ReturnItemCondition;
use App\Enums\ReturnStatus;
use App\Enums\StockMovementType;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\ReturnItem;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Scopes\BranchScope;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Books a returned parcel in, and puts back what can go back (P2-E4-T3).
 *
 * The moment the figures move, and the whole reason `ADR-035` left a debt
 * behind: cancelling a paid order in Phase 1 did **not** restock, and the
 * screen said so in as many words — "barang yang sudah keluar dibalik lewat
 * dokumen retur". This is that document.
 *
 * Four things happen, together or not at all:
 *
 * 1. **Each line is counted** — the condition somebody actually found and
 *    whether those units may go back on sale. What the customer said when they
 *    asked is an intention; this is the count, and it is the count that
 *    decides the shelf.
 * 2. **Sellable units go back through `StockLedger`** as `sale_return_in`,
 *    which is the only writer of `stocks` (ADR-024). Damaged units do not,
 *    whatever the restock flag says — `ReturnItem::isRestockable()` is one
 *    answer for the ledger and for the screens.
 * 3. **`order_items.refunded_quantity` goes up** by what came back, on every
 *    line, restocked or not. The column counts units the shop has **taken
 *    back**, not units it resold: broken goods are still off the customer's
 *    hands, and `OrderItem::netQuantity()` has to say so.
 * 4. **The request reaches `received`**, with who received it and when, and the
 *    order's timeline gets a note.
 *
 * All of it in one transaction, so a line whose warehouse cannot be worked out
 * leaves *nothing* written: the parcel is still unbooked, and whoever fixes
 * the branch's warehouses presses the button again. Half a parcel booked in is
 * a figure nobody can explain.
 *
 * **Idempotent per line.** A line already carrying `restocked_at` is skipped
 * rather than restocked twice, which is what makes a retried request safe —
 * the one failure mode that would otherwise invent stock out of nothing.
 */
final class ReceiveReturn
{
    /**
     * How many lines the most recent `handle()` call put back on a shelf.
     *
     * Read by the screen that received the parcel, so the message it shows is
     * built from what happened rather than from what usually happens — the
     * same reason `TransitionOrderStatus::$releasedHolds` exists. Telling staff
     * their stock came back when it did not is how goods go missing on paper.
     */
    public private(set) int $restockedLines = 0;

    /** How many units the most recent `handle()` call put back on a shelf. */
    public private(set) int $restockedUnits = 0;

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Book the parcel in.
     *
     * @param  array<int, array{id: int, condition: ReturnItemCondition, restock: bool, notes: string|null}>  $lines
     *                                                                                                                The counts, keyed by `return_items.id`. A line the caller leaves out
     *                                                                                                                keeps what the customer asked for, so a screen may submit only what it
     *                                                                                                                changed.
     *
     * @throws ValidationException when the request may not be received, or a line has no shelf to go back to.
     */
    public function handle(OrderReturn $return, array $lines, ?User $actor = null, ?string $notes = null): OrderReturn
    {
        $this->restockedLines = 0;
        $this->restockedUnits = 0;

        $received = DB::transaction(function () use ($return, $lines, $actor, $notes): OrderReturn {
            $locked = $this->lock($return);

            $blocked = $locked->receivingBlockedReason();

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $counts = $this->countsById($locked, $lines);

            foreach ($locked->items as $item) {
                $this->receiveLine($locked, $item, $counts[$item->getKey()] ?? null, $actor);
            }

            $locked->update([
                'status' => ReturnStatus::Received,
                'received_by' => $actor?->getKey(),
                'received_at' => now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            OrderStatusHistory::create([
                'order_id' => $locked->order_id,
                'from_status' => $locked->order->status,
                'to_status' => $locked->order->status,
                'note' => "Barang retur {$locked->code} diterima di cabang"
                    .($this->restockedUnits > 0
                        ? ", {$this->restockedUnits} unit dikembalikan ke stok."
                        : ', tidak ada unit yang dikembalikan ke stok.'),
                'changed_by' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $locked;
        });

        Log::info('Received a customer return.', [
            'return_id' => $received->getKey(),
            'code' => $received->code,
            'order_id' => $received->order_id,
            'restocked_units' => $this->restockedUnits,
            'actor_id' => $actor?->getKey(),
        ]);

        $return->refresh();

        return $received;
    }

    /**
     * Record one line's count, restock it when it may be, and take the units
     * off what the order still counts as sold.
     *
     * @param  array{id: int, condition: ReturnItemCondition, restock: bool, notes: string|null}|null  $count
     *
     * @throws ValidationException
     */
    private function receiveLine(OrderReturn $return, ReturnItem $item, ?array $count, ?User $actor): void
    {
        $item->fill([
            'condition' => $count['condition'] ?? $item->condition,
            'restock' => $count['restock'] ?? $item->restock,
            'notes' => $count['notes'] ?? $item->notes,
        ]);

        // `isRestockable()` is the one answer, and it is stricter than the
        // tick box on purpose: damaged goods never go back on the shelf
        // whatever the form said, and a line whose variant has been deleted
        // from the catalogue has no shelf to go back to. Writing the verdict
        // back onto `restock` keeps the column honest — it would otherwise
        // read "yes" beside a `restocked_at` that is for ever null.
        $item->restock = $item->isRestockable();

        if ($item->restock && ! $item->hasBeenRestocked()) {
            $warehouse = $this->restockWarehouseFor($return, $item);

            $this->ledger->apply(
                warehouse: $warehouse,
                variant: $item->orderItem->variant,
                type: StockMovementType::SaleReturnIn,
                quantityChange: $item->quantity,
                reference: $return,
                notes: "Retur penjualan {$return->code} pesanan {$return->order->order_number}.",
                actor: $actor,
            );

            $item->fill([
                'restock_warehouse_id' => $warehouse->getKey(),
                'restocked_at' => now(),
            ]);

            $this->restockedLines++;
            $this->restockedUnits += $item->quantity;
        }

        $item->save();

        // Counts units the shop has taken back, restocked or not: broken goods
        // are off the customer's hands either way, and a refund is owed for
        // them just the same.
        $item->orderItem->increment('refunded_quantity', $item->quantity);
    }

    /**
     * Which shelf these units go back on.
     *
     * **The warehouse they left from, when that can still be worked out.** The
     * order's committed hold for this variant names it, and putting units back
     * where they came from is what keeps a branch's two warehouses from
     * drifting apart on paper every time somebody returns something.
     *
     * Falling back to the branch's default warehouse is the honest second
     * answer: a hold taken at a warehouse that has since been deleted takes
     * its row with it (`stock_reservations.warehouse_id` cascades), and an
     * order whose holds were released rather than committed never recorded
     * one. The goods still have to go somewhere, and the default is where the
     * branch's stock goes when nobody has said otherwise.
     *
     * A branch with no warehouse at all is refused rather than guessed at —
     * and because this throws inside the transaction, the whole parcel stays
     * unbooked.
     *
     * @throws ValidationException
     */
    private function restockWarehouseFor(OrderReturn $return, ReturnItem $item): Warehouse
    {
        $origin = StockReservation::withoutGlobalScope(BranchScope::class)
            ->where('order_id', $return->order_id)
            ->where('product_variant_id', $item->orderItem->product_variant_id)
            ->where('status', ReservationStatus::Committed->value)
            ->with('warehouse')
            ->orderByDesc('id')
            ->first();

        if ($origin instanceof StockReservation) {
            return $origin->warehouse;
        }

        $fallback = Warehouse::query()
            ->where('branch_id', $return->branch_id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($fallback instanceof Warehouse) {
            return $fallback;
        }

        throw ValidationException::withMessages([
            'items' => "Cabang pemroses pesanan ini belum punya gudang, jadi \"{$item->orderItem->displayName()}\" "
                .'tidak dapat dikembalikan ke stok. Tambahkan gudang cabang terlebih dahulu, '
                .'atau tandai baris ini sebagai tidak dikembalikan ke stok.',
        ]);
    }

    /**
     * The submitted counts, keyed by return line id, with anything that is not
     * a line of *this* request dropped.
     *
     * Dropped rather than refused: the ids come from a browser, and a stale
     * form naming a line that has since been removed is not a reason to turn a
     * whole parcel away at the counter.
     *
     * @param  array<int, array{id: int, condition: ReturnItemCondition, restock: bool, notes: string|null}>  $lines
     * @return array<int, array{id: int, condition: ReturnItemCondition, restock: bool, notes: string|null}>
     */
    private function countsById(OrderReturn $return, array $lines): array
    {
        $own = $return->items->pluck('id')->all();
        $counts = [];

        foreach ($lines as $line) {
            if (in_array($line['id'], $own, strict: true)) {
                $counts[$line['id']] = $line;
            }
        }

        return $counts;
    }

    /**
     * The request, re-read and locked for this transaction, with everything
     * the lines need to move stock.
     *
     * `items.orderItem.variant` is loaded here rather than fetched per line,
     * because `Model::preventLazyLoading()` is on outside production and
     * because a parcel of twelve lines should not cost thirty-six queries.
     */
    private function lock(OrderReturn $return): OrderReturn
    {
        return OrderReturn::withoutGlobalScope(BranchScope::class)
            ->with(['order:id,order_number,status,branch_id', 'items.orderItem.variant'])
            ->whereKey($return->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
