<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Notification;

/**
 * Tells a branch that a paid order is waiting to be packed (P2-E6-T4).
 *
 * Database only, like `StockTransferNotification`: it is for people who are
 * already in the panel, and it lands in the bell. The figures are copied into
 * the payload so the entry keeps saying what was true when it fired.
 */
class OrderAwaitingBranchNotification extends Notification
{
    public function __construct(private Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $order = $this->order;

        return [
            'type' => 'order_awaiting_branch',
            'order_id' => $order->getKey(),
            'order_number' => $order->order_number,
            'branch_id' => $order->branch_id,
            'message' => sprintf(
                'Pesanan %s (%s, %s) sudah dibayar dan menunggu diproses.',
                $order->order_number,
                $order->customer_name,
                Money::format($order->grand_total),
            ),
        ];
    }
}
