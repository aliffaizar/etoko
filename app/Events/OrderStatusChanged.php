<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order has just reached a status — or come into existence (P2-E6-T1, T3).
 *
 * Dispatched by `TransitionOrderStatus` for every move and by `PlaceOrder` for
 * the first row of the timeline (`from` is then null). **After commit**: both
 * fire from inside their transaction, and every listener — the invoice, the
 * e-mail to the customer, the alert to the branch — describes a status that
 * must already be written. A transition that rolls back announces nothing.
 *
 * `actor` is who caused it, so staff are not told about their own click.
 * The models travel to queued listeners by key and are read fresh there.
 */
class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly ?OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly ?User $actor = null,
    ) {}

    /**
     * Whether this is the order being placed rather than moved.
     */
    public function isPlacement(): bool
    {
        return $this->from === null;
    }
}
