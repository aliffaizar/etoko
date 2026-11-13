<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Order\GenerateInvoice;
use App\Exceptions\InvoiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoice\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer downloading their own invoice (P2-E6-T2).
 *
 * Scoped by `user_id` like the rest of `/akun/pesanan`: somebody else's order
 * is not found rather than refused. `GenerateInvoice` is idempotent, so the
 * first download of a paid order whose invoice was never issued issues it.
 */
class InvoiceController extends Controller
{
    public function __invoke(Request $request, Order $order, GenerateInvoice $generateInvoice, InvoicePdf $pdf): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        abort_unless($order->user_id === $customer->getKey(), 404);

        try {
            $invoice = $generateInvoice->handle($order);
        } catch (InvoiceUnavailableException) {
            abort(404);
        }

        return $pdf->download($invoice);
    }
}
