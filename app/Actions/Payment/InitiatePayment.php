<?php

namespace App\Actions\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\Payment\Data\PaymentContext;
use App\Services\Payment\Data\PaymentInstruction;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaymentMethodEligibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asks the customer for the money (P2-E1-T2, T3, T7).
 *
 * Three steps in three phases, and the phases are the design rather than an
 * accident of implementation:
 *
 * 1. **Open the attempt, in a transaction.** Any attempt still waiting is
 *    closed first, so one order never carries two live asks for the same
 *    money, and the new row is written with its reference and its deadline.
 * 2. **Ask the gateway, outside the transaction.** `charge()` is an HTTP call
 *    to somebody else's server. Inside the transaction it would hold the
 *    order's row lock for as long as Midtrans took to answer, and every other
 *    write to that order would queue behind it. A refusal is caught, the
 *    attempt is marked failed, and the customer is told in words.
 * 3. **Record what came back, and follow it through.** The instruction's
 *    columns land on the attempt; the order's `payment_status` moves; and a
 *    COD order is confirmed outright, because that is the moment its branch
 *    starts packing it (ADR-040).
 *
 * The row is created **before** the gateway is called because the gateway has
 * to be told an id to key its transaction on, and that id has to be one we can
 * find a payment by when the callback arrives. An attempt whose charge then
 * fails is left as a failed row rather than deleted: "we asked and it was
 * refused" is a thing support needs to see.
 *
 * **Where `payment_status` goes, and why it is not always `pending`:**
 *
 * - A manual transfer leaves the order `unpaid`. Nothing has happened yet —
 *   the customer has been shown an account number. It becomes `pending` when
 *   the receipt arrives (`SubmitPaymentProof`), which is what makes
 *   "Menunggu Konfirmasi" in the panel mean *there is something for me to
 *   look at* rather than *somebody opened a checkout*.
 * - A gateway method becomes `pending` immediately: the gateway is holding
 *   the transaction and will tell us how it ends.
 * - A COD order becomes `pending` and its **status** becomes `paid`, because
 *   the goods leave the shelf before the money arrives.
 */
final class InitiatePayment
{
    public function __construct(
        private readonly PaymentManager $gateways,
        private readonly PaymentMethodEligibility $eligibility,
        private readonly ConfirmCashOnDeliveryOrder $confirmCashOnDelivery,
        private readonly FailPayment $failPayment,
    ) {}

    /**
     * Open a payment for the order and return it, ready to be acted on.
     *
     * @param  User|null  $actor  Who asked; null when the customer did it themselves is also fine — this is only for the log.
     *
     * @throws PaymentFailedException
     */
    public function handle(Order $order, PaymentMethod $method, ?User $actor = null): Payment
    {
        $this->assertPayable($order);
        $this->assertEligible($order, $method);

        $payment = $this->open($order, $method);

        try {
            $instruction = $this->gateways->for($method)->charge($payment);
        } catch (PaymentFailedException $refused) {
            $this->failPayment->handle($payment, $refused->getMessage(), $actor);

            throw $refused;
        }

        return $this->record($payment, $instruction, $actor);
    }

    /**
     * Refuse an order there is nothing to collect for.
     *
     * Checked before anything is written, and checked again under the order's
     * row lock in `open()` — the gap between the two is where a webhook
     * settling the previous attempt would land.
     *
     * @throws PaymentFailedException
     */
    private function assertPayable(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw PaymentFailedException::alreadySettled($order);
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            throw PaymentFailedException::orderNotPayable($order);
        }
    }

    /**
     * @throws PaymentFailedException
     */
    private function assertEligible(Order $order, PaymentMethod $method): void
    {
        if (! $method->is_active) {
            throw PaymentFailedException::methodInactive($method);
        }

        $reason = $this->eligibility->reasonAgainst($method, PaymentContext::forOrder($order));

        if ($reason !== null) {
            throw PaymentFailedException::methodNotEligible($method, $reason);
        }
    }

    /**
     * Write the attempt, closing any that was still waiting.
     *
     * The order is re-read under its row lock, which is what makes the second
     * payability check meaningful: a webhook that settled the previous attempt
     * between `assertPayable()` and here would have moved the order on, and
     * opening a second ask for money already received is how a customer ends
     * up paying twice.
     *
     * @throws PaymentFailedException
     */
    private function open(Order $order, PaymentMethod $method): Payment
    {
        return DB::transaction(function () use ($order, $method): Payment {
            $locked = $this->lock($order);

            $this->assertPayable($locked);

            // Closed with a reason that names what happened, so a customer who
            // switched from a virtual account to a transfer does not read
            // "Gagal" and think their money went somewhere.
            foreach ($locked->payments()->open()->get() as $superseded) {
                $superseded->update([
                    'status' => PaymentTransactionStatus::Failed,
                    'failure_reason' => 'Digantikan percobaan pembayaran yang lebih baru.',
                ]);
            }

            $fee = $method->feeFor($locked->grand_total);

            return Payment::query()->create([
                'order_id' => $locked->getKey(),
                'branch_id' => $locked->branch_id,
                'payment_method_id' => $method->getKey(),
                'method_name' => $method->name,
                'method_type' => $method->type,
                'amount' => $locked->grand_total + $fee,
                'fee_amount' => $fee,
                'status' => PaymentTransactionStatus::Pending,
                'reference' => Payment::newReferenceFor($locked),
                'expires_at' => $this->deadlineFor($locked, $method),
            ]);
        });
    }

    /**
     * The deadline for this attempt (ADR-041).
     *
     * A COD has none — its goods are committed the moment the order is
     * confirmed, so there is no hold ticking down. Everything else inherits
     * the order's, which is the earliest of its stock holds.
     */
    private function deadlineFor(Order $order, PaymentMethod $method): ?CarbonInterface
    {
        if ($method->type->isCollectedOnDelivery()) {
            return null;
        }

        return $order->paymentDeadline();
    }

    /**
     * Persist what the gateway said, and move the order accordingly.
     *
     * The gateway may shorten the deadline but never lengthen it
     * (`PaymentInstruction::deadlineAgainst()`): a virtual account that closes
     * in five minutes closes in five minutes, while one that claims twenty-four
     * hours cannot outlive the fifteen-minute hold on the goods.
     */
    private function record(Payment $payment, PaymentInstruction $instruction, ?User $actor): Payment
    {
        $settled = DB::transaction(function () use ($payment, $instruction): Payment {
            $payment->fill([
                ...$instruction->attributes(),
                'expires_at' => $instruction->deadlineAgainst($payment->expires_at),
            ]);
            $payment->save();

            $order = $this->lock($payment->order);

            $order->update([
                'payment_status' => $payment->method_type->expectsProofOfTransfer()
                    ? PaymentStatus::Unpaid
                    : PaymentStatus::Pending,
            ]);

            $payment->setRelation('order', $order);

            return $payment;
        });

        if ($settled->method_type->isCollectedOnDelivery()) {
            $this->confirmCashOnDelivery->handle($settled, $actor);
        }

        Log::info('Opened a payment attempt.', [
            'order_id' => $settled->order_id,
            'payment_id' => $settled->getKey(),
            'reference' => $settled->reference,
            'method' => $settled->method_name,
            'amount' => $settled->amount,
        ]);

        return $settled->refresh();
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: a customer paying for their own order
     * has no branch context at all, and so does the webhook worker.
     */
    private function lock(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
