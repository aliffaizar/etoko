<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Order\GenerateInvoice;
use App\Exceptions\InvoiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoice\InvoicePdf;
use Illuminate\Http\Response;

/**
 * Staff downloading an order's invoice (P2-E6-T2).
 *
 * Whoever may read the order may read its invoice: it holds nothing the order
 * screen does not already show. `Order` carries the branch scope, so another
 * branch's order answers 404 before `OrderPolicy` is asked (ADR-027).
 */
class InvoiceController extends Controller
{
    public function __invoke(Order $order, GenerateInvoice $generateInvoice, InvoicePdf $pdf): Response
    {
        $this->authorize('view', $order);

        try {
            $invoice = $generateInvoice->handle($order);
        } catch (InvoiceUnavailableException) {
            abort(404);
        }

        return $pdf->download($invoice);
    }
}
