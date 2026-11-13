<?php

namespace App\Services\Invoice;

use App\Enums\PaymentTransactionStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Response;

/**
 * Draws an invoice as a PDF (P2-E6-T2, ADR-059).
 *
 * Drawn from the database on every download rather than stored: the order's
 * lines and totals are already snapshots of checkout (ADR-007), the invoice
 * row fixes its own number, date and total, and the one thing that does move
 * afterwards — whether the money is in, which for a COD order happens on the
 * doorstep — is exactly what a re-downloaded invoice should show.
 *
 * The template is plain HTML with inline CSS 2.1, which is what dompdf reads:
 * no flex, no grid, no Tailwind. Tables do the layout.
 */
final class InvoicePdf
{
    /**
     * The invoice offered as a file download.
     */
    public function download(Invoice $invoice): Response
    {
        return $this->render($invoice)->download($invoice->fileName());
    }

    /**
     * The PDF's raw bytes, for an attachment or a test.
     */
    public function output(Invoice $invoice): string
    {
        return $this->render($invoice)->output();
    }

    private function render(Invoice $invoice): PdfDocument
    {
        // Only the glyphs the invoice uses are embedded; the whole DejaVu
        // font would make every invoice weigh close to a megabyte.
        return Pdf::loadView('invoices.order', $this->viewData($invoice))
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Invoice $invoice): array
    {
        $order = $invoice->order()->withoutGlobalScopes()->firstOrFail()
            ->load(['items', 'branch', 'payments']);

        return [
            'storeName' => (string) config('app.name'),
            'invoice' => $invoice,
            'order' => $order,
            'payment' => $this->settledPayment($order),
        ];
    }

    /**
     * The attempt the money came in on — or, for a COD order still waiting
     * at the door, the attempt that will collect it.
     */
    private function settledPayment(Order $order): ?Payment
    {
        return $order->payments->first(
            fn (Payment $payment): bool => $payment->status === PaymentTransactionStatus::Success,
        ) ?? $order->payments->first(
            fn (Payment $payment): bool => $payment->status === PaymentTransactionStatus::Pending,
        );
    }
}
