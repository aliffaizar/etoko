<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a customer where their order has got to (P2-E6-T3).
 *
 * One class for every milestone the customer is waiting on — placed, paid,
 * shipped, completed, cancelled, expired — because they share their
 * recipient, their channels and their link, and differ only in one sentence.
 * Click & Collect keeps `OrderReadyForPickupNotification`: it carries a code
 * the customer has to show, and that is a different message, not a
 * different sentence.
 *
 * **Mail and database**, like the pickup message: the customer is not in the
 * application and has no reason to open it until something changes.
 *
 * **Queued.** `NotifyCustomerOfOrderStatus` runs after the transaction that
 * moved the order commits (`OrderStatusChanged` is dispatched after commit),
 * so the worker reads a status that is already written. `afterCommit()` is
 * kept anyway, for a caller that notifies from inside a transaction of its
 * own.
 *
 * **A placement that has already been overtaken is not sent.** A COD order is
 * moved to `paid` seconds after it is placed (ADR-040), and an online one may
 * be paid before a slow worker reaches the queue; "please pay" would then
 * arrive after "thank you for paying". Only the placement is checked this
 * way — every later milestone is still news when it lands late.
 */
class OrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The statuses a customer is told about.
     *
     * `ready_for_pickup` is absent on purpose — see the class docblock — and
     * so are `processing` and `delivered`: the first is the branch's own
     * business, the second the customer saw with their own eyes. Refunds have
     * their own documents (P2-E4).
     *
     * @var array<int, OrderStatus>
     */
    public const ANNOUNCED = [
        OrderStatus::PendingPayment,
        OrderStatus::Paid,
        OrderStatus::Shipped,
        OrderStatus::Completed,
        OrderStatus::Cancelled,
        OrderStatus::Expired,
    ];

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $status,
    ) {
        $this->afterCommit();
    }

    public static function announces(OrderStatus $status): bool
    {
        return in_array($status, self::ANNOUNCED, strict: true);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->status !== OrderStatus::PendingPayment) {
            return true;
        }

        return $this->order->fresh()?->status === OrderStatus::PendingPayment;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['branch', 'shipments']);

        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hai {$order->customer_name},");

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        $message->action($this->actionText(), route('shop.account.orders.show', $order));

        if ($this->status === OrderStatus::Paid) {
            $message->line('Invoice pesanan ini dapat diunduh dari halaman pesanan.');
        }

        return $message;
    }

    /**
     * What the customer reads, copied into the row so it keeps saying what
     * was true when it fired.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'order_status',
            'order_id' => $this->order->getKey(),
            'order_number' => $this->order->order_number,
            'status' => $this->status->value,
            'message' => $this->summary(),
        ];
    }

    private function subject(): string
    {
        $number = $this->order->order_number;

        return match ($this->status) {
            OrderStatus::PendingPayment => "Pesanan {$number} kami terima",
            OrderStatus::Paid => $this->isCashOnDelivery()
                ? "Pesanan {$number} dikonfirmasi"
                : "Pembayaran pesanan {$number} diterima",
            OrderStatus::Shipped => "Pesanan {$number} sudah dikirim",
            OrderStatus::Completed => "Pesanan {$number} selesai",
            OrderStatus::Cancelled => "Pesanan {$number} dibatalkan",
            OrderStatus::Expired => "Pesanan {$number} dibatalkan otomatis",
            default => "Status pesanan {$number}: {$this->status->label()}",
        };
    }

    /**
     * One sentence for the notification feed.
     */
    private function summary(): string
    {
        return $this->lines()[0];
    }

    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        $order = $this->order;
        $number = $order->order_number;
        $total = Money::format($order->grand_total);

        return match ($this->status) {
            OrderStatus::PendingPayment => array_filter([
                "Pesanan {$number} sebesar {$total} sudah kami terima.",
                $this->deadlineLine(),
            ]),
            OrderStatus::Paid => $this->isCashOnDelivery()
                ? [
                    "Pesanan {$number} dikonfirmasi dan sedang disiapkan.",
                    "Siapkan pembayaran tunai {$total} saat kurir tiba.",
                ]
                : [
                    "Pembayaran {$total} untuk pesanan {$number} sudah kami terima.",
                    'Pesanan Anda segera disiapkan oleh cabang.',
                ],
            OrderStatus::Shipped => array_filter([
                "Pesanan {$number} sudah dikirim.",
                $this->shipmentLine(),
            ]),
            OrderStatus::Completed => [
                "Pesanan {$number} selesai. Terima kasih sudah berbelanja.",
            ],
            OrderStatus::Cancelled => array_filter([
                "Pesanan {$number} dibatalkan".($order->cancel_reason === null ? '.' : ": {$order->cancel_reason}"),
                $order->paid_at === null
                    ? null
                    : 'Pengembalian dana untuk pembayaran yang sudah masuk akan kami proses dan kabarkan terpisah.',
            ]),
            OrderStatus::Expired => [
                "Batas waktu pembayaran pesanan {$number} terlewat, sehingga pesanan dibatalkan otomatis.",
                'Silakan pesan kembali bila Anda masih menginginkan barangnya.',
            ],
            default => ["Status pesanan {$number} sekarang: {$this->status->label()}."],
        };
    }

    private function actionText(): string
    {
        return $this->status === OrderStatus::PendingPayment ? 'Bayar sekarang' : 'Lihat pesanan';
    }

    private function deadlineLine(): ?string
    {
        $deadline = $this->order->paymentDeadline();

        if ($deadline === null) {
            return null;
        }

        return 'Selesaikan pembayaran sebelum '
            .$deadline->timezone(config('app.timezone'))->translatedFormat('j F Y, H:i')
            .' WIB agar pesanan tidak dibatalkan otomatis.';
    }

    private function shipmentLine(): ?string
    {
        $shipment = $this->order->activeShipment() ?? $this->order->shipments->first();

        if ($shipment === null) {
            return null;
        }

        $courier = trim("{$shipment->courier_name} {$shipment->service_name}");

        return $shipment->tracking_number === null
            ? "Kurir: {$courier}."
            : "Kurir: {$courier}, nomor resi {$shipment->tracking_number}.";
    }

    /**
     * Whether the money is collected at the door (ADR-040): such an order
     * reaches `paid` with its `payment_status` still pending.
     */
    private function isCashOnDelivery(): bool
    {
        return $this->status === OrderStatus::Paid
            && $this->order->payment_status !== PaymentStatus::Paid;
    }
}
