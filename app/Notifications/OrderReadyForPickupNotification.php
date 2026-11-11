<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a customer their Click & Collect order is waiting at the branch
 * (P2-E2-T7).
 *
 * Emailed as well as stored, unlike `LowStockNotification`: this one is for
 * somebody who is *not* in the application and has no reason to open it again
 * until something changes. An order sitting on a collection shelf that nobody
 * was told about is the whole failure mode of Click & Collect.
 *
 * **Queued, and `afterCommit`.** `TransitionOrderStatus` fires this from inside
 * its transaction, which is where it belongs — the notification and the status
 * that caused it either both happen or neither does. But the job must not be
 * picked up before that transaction commits, or a worker would look for an
 * order whose `ready_for_pickup` has not been written yet and send a message
 * about a state that may still roll back.
 *
 * **The figures are copied into the payload**, not looked up when the
 * notification is read. A notification is a record of what was true when it
 * fired; resolving it later would make last week's message quietly describe
 * today's order, and the pickup code in particular has to be the one that was
 * issued — reading it live would silently reissue a stale message with a
 * number that works.
 *
 * The rest of the order notifications — placed, paid, shipped, completed,
 * cancelled — are `P2-E6-T3`. This one is here because `P2-E2-T7` is the flow
 * that cannot work without it.
 */
class OrderReadyForPickupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Order $order)
    {
        // Wait for the transaction that fired this to commit before a worker
        // picks it up — see the class docblock. Set through the trait's own
        // method rather than by redeclaring `$afterCommit`, which `Queueable`
        // declares untyped and PHP refuses to see narrowed.
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $branch = $this->order->branch;

        $message = (new MailMessage)
            ->subject("Pesanan {$this->order->order_number} siap diambil")
            ->greeting("Hai {$this->order->customer_name},")
            ->line("Pesanan {$this->order->order_number} sudah siap diambil di {$branch->name}.")
            ->line("Alamat: {$branch->address_line}".($branch->city_name === null ? '' : ", {$branch->city_name}"));

        if ($this->order->pickup_code !== null) {
            $message->line("Tunjukkan kode pengambilan ini di kasir: **{$this->order->pickup_code}**");
        }

        return $message
            ->action('Lihat pesanan', route('shop.account.orders.show', $this->order))
            ->line('Bawa identitas diri bila yang mengambil bukan Anda sendiri.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'order_ready_for_pickup',
            'order_id' => $this->order->getKey(),
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'branch_name' => $this->order->branch->name,
            'pickup_code' => $this->order->pickup_code,
            'message' => sprintf(
                'Pesanan %s siap diambil di %s.',
                $this->order->order_number,
                $this->order->branch->name,
            ),
        ];
    }
}
