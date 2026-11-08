<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Payment\InitiatePayment;
use App\Actions\Payment\SubmitPaymentProof;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\InitiatePaymentRequest;
use App\Http\Requests\Shop\UploadPaymentProofRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

/**
 * How a customer pays for their own order (P2-E1-T3).
 *
 * Scoped by `user_id`, not by policy — the same arrangement as
 * `Shop\OrderController`, and for the same reason: a shopper holds no
 * `payments.*` permission at all, and letting one through `PaymentPolicy`
 * would mean deciding they may "view any payment". Here the relationship *is*
 * the authorisation, so somebody else's order is **not found** rather than
 * refused, and nothing leaks about it existing.
 *
 * There is no payment screen of its own. The order's detail page already shows
 * the order, and the payment panel belongs on it: a second screen would be a
 * second place for the total to be right, and a customer who closed it would
 * have nowhere obvious to go back to. Both endpoints here therefore answer
 * `back()` with a flash, as the project's Inertia convention requires
 * (`BUG-09`).
 *
 * **Choosing a method is a write that can be repeated.** A customer who picks
 * a virtual account, changes their mind and picks a transfer leaves the first
 * attempt closed and a second one open — `InitiatePayment` sees to that — so
 * this endpoint is both the "pay now" button and the "pay a different way"
 * button, and there is only one of it.
 */
class PaymentController extends Controller
{
    /**
     * Start, or restart, paying for the order.
     */
    public function store(
        InitiatePaymentRequest $request,
        Order $order,
        InitiatePayment $initiatePayment,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        $this->assertOwnedBy($order, $customer);

        $method = PaymentMethod::query()->findOrFail($request->paymentMethodId());

        try {
            $payment = $initiatePayment->handle($order, $method, $customer);
        } catch (PaymentFailedException $refused) {
            return back()->withErrors(['payment_method_id' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->startedMessage($payment),
        ]);

        return back();
    }

    /**
     * Upload the receipt for a manual transfer.
     *
     * The payment is reached **through the order** rather than bound from the
     * URL on its own, so an id belonging to somebody else's order is not found
     * instead of being refused — the same property the order scoping above
     * gives.
     */
    public function uploadProof(
        UploadPaymentProofRequest $request,
        Order $order,
        Payment $payment,
        SubmitPaymentProof $submitProof,
    ): RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        $this->assertOwnedBy($order, $customer);
        abort_unless($payment->order_id === $order->getKey(), 404);

        /** @var UploadedFile $file */
        $file = $request->file('proof');

        try {
            $submitProof->handle($payment, $file, $request->note());
        } catch (PaymentFailedException $refused) {
            return back()->withErrors(['proof' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Bukti transfer terkirim. Cabang akan memeriksanya dan mengonfirmasi pembayaran Anda.',
        ]);

        return back();
    }

    /**
     * What to tell the customer about the attempt that just opened.
     *
     * Built from what the method actually is, because the next step genuinely
     * differs: a COD has nothing to do, a gateway sends them somewhere, and a
     * transfer needs a receipt afterwards.
     */
    private function startedMessage(Payment $payment): string
    {
        if ($payment->method_type->isCollectedOnDelivery()) {
            return "Pesanan dikonfirmasi. Siapkan pembayaran tunai {$this->rupiah($payment->amount)} saat kurir tiba.";
        }

        if ($payment->payment_url !== null) {
            return 'Lanjutkan pembayaran di halaman penyedia pembayaran yang terbuka berikut.';
        }

        return "Metode pembayaran {$payment->method_name} dipilih. Ikuti instruksi pembayaran di bawah.";
    }

    private function rupiah(int $amount): string
    {
        return Money::format($amount);
    }

    /**
     * A customer may only reach their own order.
     *
     * 404 rather than 403: another customer's order must not be confirmed to
     * exist by the refusal that hides it.
     */
    private function assertOwnedBy(Order $order, User $customer): void
    {
        abort_unless($order->user_id === $customer->getKey(), 404);
    }
}
