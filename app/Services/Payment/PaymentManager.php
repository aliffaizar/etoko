<?php

namespace App\Services\Payment;

use App\Enums\PaymentDriver;
use App\Exceptions\PaymentFailedException;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payment\Drivers\ManualGateway;
use App\Services\Payment\Drivers\MidtransGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the gateway that carries out a payment (P2-E1-T2, ADR-004).
 *
 * The one place that knows which class serves which `payment_methods.driver`
 * value. Everything else — the actions, the controllers, the webhook — is
 * written against `PaymentGateway` and never names a driver, which is what
 * made adding Midtrans in `P2-E1-T5` a matter of one `match` arm.
 *
 * Drivers come out of the container, so a test swaps one with
 * `$this->app->instance()` and a driver may take its own dependencies. They
 * are **not** cached per driver: a gateway holds no per-payment state, but
 * caching would silently keep a test's stub alive past the binding that
 * replaced it.
 *
 * `Xendit` is a value the schema allows and the application cannot yet serve,
 * so it is refused here with a sentence rather than further down with a
 * container error. That is the difference between "we have not built this yet"
 * and a 500.
 */
final class PaymentManager
{
    public function __construct(private readonly Container $container) {}

    /**
     * The gateway for a payment, by the method it was made with.
     *
     * Read through the `paymentMethod` relation rather than the snapshot
     * columns on purpose: the snapshots describe how the payment was *made*,
     * while the driver is who to talk to *now* — and a shop that moves a
     * method from manual to Midtrans wants its open attempts handled by the
     * gateway that is live, not by the one that was.
     */
    public function forPayment(Payment $payment): PaymentGateway
    {
        return $this->for($payment->paymentMethod);
    }

    public function for(PaymentMethod $method): PaymentGateway
    {
        return $this->driver($method->driver);
    }

    public function driver(PaymentDriver $driver): PaymentGateway
    {
        return match ($driver) {
            PaymentDriver::Manual => $this->container->make(ManualGateway::class),
            PaymentDriver::Midtrans => $this->container->make(MidtransGateway::class),
            PaymentDriver::Xendit => throw PaymentFailedException::unsupported(
                'Driver pembayaran Xendit belum tersedia. Pilih metode pembayaran lain.',
            ),
        };
    }
}
