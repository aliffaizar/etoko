<?php

namespace App\Actions\Order;

use App\Exceptions\InvoiceUnavailableException;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\DocumentNumber;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Gives a paid order its invoice, or returns the one it already has
 * (P2-E6-T1, ADR-059).
 *
 * **Idempotent by design**, because it is reached from two places that may
 * both arrive: `IssueInvoiceForPaidOrder` right after the order is paid, and
 * the download routes, which call it so an order whose listener never ran — a
 * crash between commit and listener, or an order paid before this existed —
 * heals the first time somebody asks for the document.
 *
 * **Runs outside the payment's transaction.** `DocumentNumber` takes a row
 * lock on its counter, and nested inside `TransitionOrderStatus` that lock
 * would be held for the rest of the payment's work, queueing every payment
 * in the shop behind one row (MED-06). The listener is therefore dispatched
 * after commit, and the number is reserved before the insert.
 *
 * Two callers racing for the same order both reserve a number; the unique
 * index on `invoices.order_id` lets exactly one insert through, and the other
 * returns the winner's row. The burned number is the same cheap loss a failed
 * checkout costs.
 */
final class GenerateInvoice
{
    /**
     * @throws InvoiceUnavailableException when the order has not been paid for.
     */
    public function handle(Order $order): Invoice
    {
        $existing = $this->existingFor($order);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $reason = $order->invoiceBlockedReason();

        if ($reason !== null) {
            throw new InvoiceUnavailableException($reason);
        }

        $number = DocumentNumber::next(DocumentNumber::PREFIX_INVOICE);

        try {
            $invoice = Invoice::create([
                'order_id' => $order->getKey(),
                'invoice_number' => $number,
                'issued_at' => now(),
                'total' => $order->grand_total,
            ]);
        } catch (UniqueConstraintViolationException) {
            $invoice = $this->existingFor($order) ?? throw new InvoiceUnavailableException(
                'Invoice pesanan ini tidak dapat dibuat. Coba lagi.',
            );
        }

        $order->setRelation('invoice', $invoice);

        return $invoice;
    }

    private function existingFor(Order $order): ?Invoice
    {
        return Invoice::query()->where('order_id', $order->getKey())->first();
    }
}
