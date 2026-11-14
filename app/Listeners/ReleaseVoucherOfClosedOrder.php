<?php

namespace App\Listeners;

use App\Actions\Voucher\ReleaseVoucher;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;

/**
 * Returns an order's voucher to the pool once the order is cancelled or has
 * expired unpaid (P2-E5-T4).
 *
 * Hung on `OrderStatusChanged`, the one place side effects of an order status
 * attach (ADR-060). Synchronous rather than queued: the customer whose order
 * just expired is likely to try again straight away, and a voucher that comes
 * back a queue-worker later would refuse them for no reason they can see.
 *
 * A refund does not release it — the goods were sold with the discount, and
 * the money that goes back is the discounted figure (ADR-064).
 */
class ReleaseVoucherOfClosedOrder
{
    public function __construct(private ReleaseVoucher $releaseVoucher) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! in_array($event->to, [OrderStatus::Cancelled, OrderStatus::Expired], strict: true)) {
            return;
        }

        $this->releaseVoucher->handle($event->order);
    }
}
