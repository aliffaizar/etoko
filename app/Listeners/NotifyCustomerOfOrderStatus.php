<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\User;
use App\Notifications\OrderReadyForPickupNotification;
use App\Notifications\OrderStatusNotification;

/**
 * Tells the customer when their order reaches a status they are waiting on
 * (P2-E2-T7, P2-E6-T3).
 *
 * Runs synchronously — it only decides who hears what — and the notifications
 * it sends are queued, so the request that moved the order is not held up by
 * a mail server.
 *
 * A guest order (POS, Phase 3) has no account to notify, and the counter has
 * the customer standing in front of it anyway. A customer who moved the order
 * themselves — cancelling it from their own page — is still told: the e-mail
 * is their receipt of the cancellation, unlike a staff member's click, which
 * is its own confirmation.
 */
class NotifyCustomerOfOrderStatus
{
    public function handle(OrderStatusChanged $event): void
    {
        $customer = $event->order->user;

        if (! $customer instanceof User) {
            return;
        }

        if ($event->to === OrderStatus::ReadyForPickup) {
            $customer->notify(new OrderReadyForPickupNotification($event->order));

            return;
        }

        if (OrderStatusNotification::announces($event->to)) {
            $customer->notify(new OrderStatusNotification($event->order, $event->to));
        }
    }
}
