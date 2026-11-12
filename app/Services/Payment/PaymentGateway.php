<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\Data\PaymentStatusResult;
use App\Services\Payment\Data\RefundResult;

/**
 * How money is collected, whoever collects it (P2-E1-T2, ADR-004).
 *
 * Three methods, one for each thing that can happen to a payment, and nothing
 * about screens or routes: an implementation is given a `Payment` row and
 * answers with a value object. That is the whole seam — `InitiatePayment`,
 * `ReconcilePayment` and the panel screens are written against this
 * interface, so a gateway can be added without any of them changing.
 *
 * **There is no callback method, and no webhook behind this interface**
 * (ADR-053). A provider never tells the application anything: the application
 * asks, through `inquire()`, with its own credentials. That leaves no
 * unauthenticated endpoint for anybody to call, and no signature to get wrong.
 *
 * Implementations must hold to two rules, because the actions above rely on
 * them:
 *
 * 1. **No writes.** A driver reads the payment and talks to the outside world;
 *    persisting the result is the action's job, inside its own transaction.
 *    A driver that saved its own row would make a failed transaction a
 *    half-finished one.
 * 2. **`charge()` and `inquire()` throw when the provider cannot answer.** A
 *    charge is a foreground request somebody is waiting on, so a failure is an
 *    exception with a sentence for the customer. An inquiry that fails must
 *    never be read as "unpaid", so it is an exception too rather than a
 *    result that looks like one.
 */
interface PaymentGateway
{
    /**
     * Ask for the money, and return what the customer must do.
     *
     * @throws PaymentFailedException when the gateway refuses or cannot be reached.
     */
    public function charge(Payment $payment): PaymentInstruction;

    /**
     * Ask the provider what became of a payment.
     *
     * The only way a gateway payment's status ever reaches the application
     * (ADR-053). The answer is trustworthy because it is read from the
     * provider's own API with our credentials rather than handed to us.
     * "Nothing has happened yet" is an `ignored()` result, not an error.
     *
     * @throws PaymentFailedException when the provider cannot be asked, so the
     *                                caller can tell "still unpaid" from "unknown".
     */
    public function inquire(Payment $payment): PaymentStatusResult;

    /**
     * Ask for `$amount` of a settled payment to be sent back.
     */
    public function refund(Payment $payment, int $amount): RefundResult;
}
