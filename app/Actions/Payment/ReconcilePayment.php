<?php

namespace App\Actions\Payment;

use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentManager;
use Illuminate\Support\Facades\Log;

/**
 * Asks the provider what became of an open attempt, and applies the answer
 * (P2-E1-T6, ADR-052, ADR-053).
 *
 * **This is the only way a gateway payment is ever settled.** There is no
 * webhook: nothing a provider sends is listened for, so there is no public
 * endpoint to forge a settlement against and nothing that depends on the
 * provider being able to reach this machine. The application asks instead, in
 * three places:
 *
 * - the customer's order page, on every visit and on its own poll while an
 *   attempt is open — that is where the customer is looking while they pay;
 * - `ReconcileOpenPayments`, on a schedule, for the customer who paid from
 *   their banking app and never came back to the page;
 * - `ExpireStockReservations`, before it expires an order or closes an
 *   attempt, so nothing is expired on an answer nobody asked for.
 *
 * The answer goes through `ApplyPaymentStatus`, so all three mean exactly the
 * same thing by a settlement, and whichever asks second is a no-op.
 */
final class ReconcilePayment
{
    public function __construct(
        private readonly PaymentManager $gateways,
        private readonly ApplyPaymentStatus $applyPaymentStatus,
    ) {}

    /**
     * Bring one attempt up to date with its provider.
     *
     * Returns whether the provider **could be asked** — not whether anything
     * changed. False means the attempt's real status is unknown, which is the
     * one thing a caller about to expire it needs to know. An attempt that is
     * already closed has nothing to ask about and answers true.
     */
    public function handle(Payment $payment): bool
    {
        if (! $payment->isOpen()) {
            return true;
        }

        try {
            $result = $this->gateways->forPayment($payment)->inquire($payment);
        } catch (PaymentFailedException $unanswered) {
            Log::warning('Could not ask the payment provider about an attempt.', [
                'payment_id' => $payment->getKey(),
                'reference' => $payment->reference,
                'reason' => $unanswered->getMessage(),
            ]);

            return false;
        }

        // An answer that changes nothing is not written down: the order page
        // asks on every visit, and a row rewritten each time would bury the
        // attempt's audit trail under "still pending".
        if ($result->changesState()) {
            $this->applyPaymentStatus->handle($payment, $result);
            $payment->refresh();
        }

        return true;
    }

    /**
     * Bring every open attempt of an order up to date.
     *
     * Returns whether any of them closed, so a caller holding the order knows
     * to re-read it.
     */
    public function forOrder(Order $order): bool
    {
        $changed = false;

        foreach ($order->payments()->open()->with('paymentMethod')->get() as $payment) {
            $this->handle($payment);

            $changed = $changed || ! $payment->isOpen();
        }

        return $changed;
    }
}
