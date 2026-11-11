<?php

namespace App\Actions\Shipping;

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\ShippingFailedException;
use App\Models\Order;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;

/**
 * Hands a Click & Collect order over at the counter (P2-E2-T7).
 *
 * The last step of a flow that has no courier in it: the branch marks the
 * order `ready_for_pickup`, which issues a code and emails it to the customer
 * (`TransitionOrderStatus`); the customer turns up and reads the code out; this
 * checks it and completes the order.
 *
 * **The code is checked here, not in the form request.** A validation rule
 * would have to fetch the order to compare against, and the comparison is the
 * decision rather than the shape of the input — so it belongs with the write,
 * inside the lock, where two tills cannot both accept the same code for the
 * same order. `Order::matchesPickupCode()` is what compares it, with
 * `hash_equals`, because the code is the one thing standing between a stranger
 * and somebody else's parcel.
 *
 * **The code can be waived, and who may waive it is the caller's business.** A
 * customer who has lost their phone is a real counter problem, and a shop with
 * no way past the code would end up writing the codes down. So the override is
 * explicit, recorded in the order's timeline with the actor's name, and never
 * the default.
 */
final class ConfirmOrderPickup
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * Complete `$order` as collected.
     *
     * @param  string|null  $code  What the customer read out; null only with `$override`.
     * @param  bool  $override  Accept without a matching code, on the actor's authority.
     *
     * @throws ShippingFailedException when the order is not waiting to be collected, or the code is wrong.
     * @throws IllegalOrderTransitionException when the order has moved on since the screen was drawn.
     */
    public function handle(
        Order $order,
        ?string $code,
        ?User $actor = null,
        bool $override = false,
        ?string $note = null,
    ): Order {
        return DB::transaction(function () use ($order, $code, $actor, $override, $note): Order {
            $locked = $this->lock($order);
            $blocked = $locked->pickupConfirmationBlockedReason();

            if ($blocked !== null) {
                throw ShippingFailedException::unsupported($blocked);
            }

            if (! $override && ! $locked->matchesPickupCode((string) $code)) {
                throw ShippingFailedException::unsupported(
                    'Kode pengambilan tidak cocok. Minta pelanggan menunjukkan kode dari email atau halaman pesanannya.',
                );
            }

            return $this->transitionOrderStatus->handle(
                $locked,
                OrderStatus::Completed,
                $actor,
                $this->timelineNote($override, $note),
            );
        });
    }

    /**
     * What the order's timeline says about the handover.
     *
     * A waived code says so in the record, because "we gave the goods to
     * somebody who could not prove it was theirs" is exactly the sentence a
     * dispute needs to find.
     */
    private function timelineNote(bool $override, ?string $note): string
    {
        $base = $override
            ? 'Pesanan diambil di cabang tanpa kode pengambilan (diizinkan staf).'
            : 'Pesanan diambil di cabang, kode pengambilan sesuai.';

        return $note === null || trim($note) === '' ? $base : $base.' '.trim($note);
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: the key already identifies the row, and
     * the caller has been through `OrderPolicy` and the branch scope to get
     * here. A scope applied again would only make this fail for a central
     * account acting on a branch's order.
     */
    private function lock(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
