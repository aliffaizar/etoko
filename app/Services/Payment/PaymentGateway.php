<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Services\Payment\Data\PaymentCallbackResult;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\Data\RefundResult;

/**
 * How money is collected, whoever collects it (P2-E1-T2, ADR-004).
 *
 * Three methods, one for each thing that can happen to a payment, and nothing
 * about screens or routes: an implementation is given a `Payment` row and
 * answers with a value object. That is the whole seam — `InitiatePayment`,
 * `HandlePaymentCallback` and the panel screens are written against this
 * interface, so `P2-E1-T5` added a gateway without any of them changing.
 *
 * Implementations must hold to three rules, because the actions above rely on
 * them:
 *
 * 1. **No writes.** A driver reads the payment and talks to the outside world;
 *    persisting the result is the action's job, inside its own transaction.
 *    A driver that saved its own row would make a failed transaction a
 *    half-finished one.
 * 2. **`charge()` throws, `handleCallback()` does not.** A charge is a
 *    foreground request somebody is waiting on, so a failure is an exception
 *    with a sentence for the customer. A callback is a gateway's retry loop, so
 *    a failure is a `PaymentCallbackResult::rejected()` the controller can turn
 *    into a status code — a thrown exception would have the gateway retrying
 *    for hours.
 * 3. **`handleCallback()` is given nothing but the payload.** It verifies the
 *    signature itself and names the payment by our own `reference`; it must
 *    never trust an id from the URL or a payment handed to it, because the
 *    caller is an unauthenticated endpoint.
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
     * Work out what an incoming callback means, verifying it first.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): PaymentCallbackResult;

    /**
     * Ask for `$amount` of a settled payment to be sent back.
     */
    public function refund(Payment $payment, int $amount): RefundResult;
}
