<?php

namespace App\Listeners;

use App\Actions\Order\GenerateInvoice;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Issues the invoice the moment an order is paid for (P2-E6-T1, ADR-059).
 *
 * Queued rather than run in the request: the request that pays an order is
 * often a customer's page polling the gateway, and a failure to number a
 * document must not turn their "paid" into an error page. The worker retries,
 * and the download routes call the same idempotent action, so an invoice that
 * never got issued here is issued the first time somebody asks for it.
 */
class IssueInvoiceForPaidOrder implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(private GenerateInvoice $generateInvoice) {}

    public function shouldQueue(OrderStatusChanged $event): bool
    {
        return $event->to === OrderStatus::Paid;
    }

    public function handle(OrderStatusChanged $event): void
    {
        $this->generateInvoice->handle($event->order);
    }
}
