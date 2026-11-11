<?php

namespace App\Actions\Returns;

use App\Enums\ReturnItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a return request (P2-E4-T2).
 *
 * The customer's end of the flow, and the only place a `returns` row is
 * created. Nothing moves: no stock, no money, no order status. What it writes
 * is a document with a number, the lines being sent back, the reason, and the
 * photos the customer attached — the branch decides next.
 *
 * Three things it refuses, each for a different reason:
 *
 * 1. **An order whose goods have not arrived yet**
 *    (`Order::returnBlockedReason()`). Goods can only come back once they have
 *    gone; an order still being packed is *cancelled*, which is a different
 *    document with a different consequence for stock.
 * 2. **A line that is not on the order.** Checked against the order's own
 *    lines rather than trusted from the form, because the id comes from a
 *    browser.
 * 3. **More units than are left to send back.** Counted under the order's row
 *    lock, across every request that is still alive — so two requests racing
 *    for the same two units end with one of them refused rather than both
 *    accepted (`OrderItem::returnableQuantity()`).
 *
 * The document number is taken **before** the transaction opens (ADR-037): a
 * `DB::transaction()` nested in another is only a savepoint, so taking it
 * inside would hold the counter's row lock for the length of this work and
 * serialise every return in the shop behind one row.
 */
final class RequestReturn
{
    /**
     * Open a request for the given order.
     *
     * @param  array{type: ReturnType, reason: ReturnReason, description: string|null, photo_paths: array<int, string>, items: array<int, array{order_item_id: int, quantity: int}>}  $data
     *
     * @throws ValidationException when the order may not be returned, or a line asks for more than is left.
     */
    public function handle(Order $order, User $customer, array $data): OrderReturn
    {
        $blocked = $order->returnBlockedReason();

        if ($blocked !== null) {
            throw ValidationException::withMessages(['reason' => $blocked]);
        }

        $code = DocumentNumber::next(DocumentNumber::PREFIX_RETURN);

        return DB::transaction(function () use ($order, $customer, $data, $code): OrderReturn {
            $locked = $this->lockOrder($order);

            $this->assertStillReturnable($locked);

            $lines = $this->resolveLines($locked, $data['items']);

            $return = OrderReturn::create([
                'code' => $code,
                'order_id' => $locked->getKey(),
                'user_id' => $customer->getKey(),
                'branch_id' => $locked->branch_id,
                'type' => $data['type'],
                'reason' => $data['reason'],
                'description' => $data['description'],
                'photo_paths' => $data['photo_paths'] === [] ? null : $data['photo_paths'],
                'status' => ReturnStatus::Requested,
                'requested_at' => now(),
            ]);

            foreach ($lines as $line) {
                ReturnItem::create([
                    'return_id' => $return->getKey(),
                    'order_item_id' => $line['item']->getKey(),
                    'quantity' => $line['quantity'],
                    // What the customer says, as an intention. The branch
                    // writes what it actually finds when the parcel is opened
                    // (`ReceiveReturn`), and that is what decides the shelf.
                    'condition' => $data['reason'] === ReturnReason::Damaged
                        ? ReturnItemCondition::Damaged
                        : ReturnItemCondition::Good,
                    'restock' => $data['reason'] !== ReturnReason::Damaged,
                ]);
            }

            return $return->load('items.orderItem');
        });
    }

    /**
     * Refuse an order that stopped being returnable between the form and the
     * submit.
     *
     * @throws ValidationException
     */
    private function assertStillReturnable(Order $order): void
    {
        $blocked = $order->returnBlockedReason();

        if ($blocked !== null) {
            throw ValidationException::withMessages(['reason' => $blocked]);
        }
    }

    /**
     * The order's own lines for the submitted ids, with each quantity checked
     * against what is actually left.
     *
     * @param  array<int, array{order_item_id: int, quantity: int}>  $items
     * @return array<int, array{item: OrderItem, quantity: int}>
     *
     * @throws ValidationException
     */
    private function resolveLines(Order $order, array $items): array
    {
        $lines = $order->items()
            ->with('returnItems.return')
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($items as $index => $submitted) {
            $item = $lines->get($submitted['order_item_id']);

            if (! $item instanceof OrderItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.order_item_id" => 'Barang yang dipilih bukan bagian dari pesanan ini.',
                ]);
            }

            $available = $item->returnableQuantity();

            if ($submitted['quantity'] > $available) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => $available === 0
                        ? "\"{$item->displayName()}\" sudah seluruhnya diajukan retur."
                        : "Maksimal {$available} unit \"{$item->displayName()}\" yang masih dapat diretur.",
                ]);
            }

            $resolved[] = ['item' => $item, 'quantity' => $submitted['quantity']];
        }

        if ($resolved === []) {
            throw ValidationException::withMessages([
                'items' => 'Pilih minimal satu barang yang ingin diretur.',
            ]);
        }

        return $resolved;
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the caller is a customer, who is never
     * branch-restricted (ADR-028), and the key already identifies the row.
     * What the lock is for is the quantity arithmetic — two requests for the
     * same units queue here rather than both passing the check.
     */
    private function lockOrder(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
