<?php

namespace App\Actions\Returns;

use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\Payment\Data\RefundResult;
use App\Services\Payment\PaymentManager;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sends money back to the customer (P2-E4-T4).
 *
 * Three steps in three phases, the same shape `InitiatePayment` has and for
 * the same reason:
 *
 * 1. **Open the refund, in a transaction.** The amount is checked against what
 *    the order has left to give back, under the order's row lock, so two
 *    refunds racing for the same remaining rupiah end with one refused rather
 *    than both written. The row is created `pending`.
 * 2. **Ask the gateway, outside the transaction.** `refund()` is an HTTP call
 *    to somebody else's server; inside the transaction it would hold the
 *    order's row lock for as long as the provider took to answer, and every
 *    other write to that order would queue behind it.
 * 3. **Record what came back.** A reversal the provider has already carried
 *    out is settled through `SettleRefund`, which is what moves the order. One
 *    it has merely accepted stays `pending` with its id on it, for the
 *    callback or for somebody to check. A refusal is written onto the row as a
 *    failure, because "we asked and it was refused" is a thing support needs
 *    to see.
 *
 * A manual method — a bank transfer, cash over the counter — has no provider
 * to ask. It is opened `pending`, and whoever moves the money presses
 * "Tandai sudah dikembalikan" afterwards (`SettleRefund`). A staff member who
 * has *already* made the transfer may say so in the same breath by passing the
 * reference, and then it is settled at once. What is never done is settling it
 * silently: money the application claims went back and nobody sent is the one
 * outcome worse than a slow refund.
 *
 * The document number is taken **before** the transaction opens (ADR-037), so
 * the counter's row lock is not held for the length of this work.
 */
final class ProcessRefund
{
    public function __construct(
        private readonly PaymentManager $gateways,
        private readonly SettleRefund $settleRefund,
    ) {}

    /**
     * Open a refund against an order and carry it as far as it can go.
     *
     * @param  array{amount: int, method: RefundMethod, return_id: int|null, payment_id: int|null, account_info: string|null, reference: string|null, notes: string|null}  $data
     *
     * @throws ValidationException when the order has no money left to give back, or the method cannot be served.
     */
    public function handle(Order $order, array $data, ?User $actor = null): Refund
    {
        $this->assertMethodAvailable($data['method']);

        $return = $this->resolveReturn($order, $data['return_id']);
        $payment = $this->resolvePayment($order, $data['payment_id'], $data['method']);

        $code = DocumentNumber::next(DocumentNumber::PREFIX_REFUND);

        $refund = $this->open($order, $return, $payment, $data, $code, $actor);

        if (! $data['method']->isAutomatic()) {
            // A person moves the money. Settled now only if they say they
            // already have, by giving the reference.
            if ($data['reference'] !== null && $data['reference'] !== '') {
                $this->settleRefund->handle($refund, $actor, $data['reference']);
            }

            return $refund->refresh();
        }

        return $this->carryOutThroughGateway($refund, $payment, $actor);
    }

    /**
     * Refuse a way of refunding the application cannot serve.
     *
     * @throws ValidationException
     */
    private function assertMethodAvailable(RefundMethod $method): void
    {
        $unavailable = $method->unavailableReason();

        if ($unavailable !== null) {
            throw ValidationException::withMessages(['method' => $unavailable]);
        }
    }

    /**
     * The return this refund answers, checked against the order.
     *
     * Null is a real case rather than a missing value (ADR-045): money goes
     * back without goods coming back whenever there were no goods to send — a
     * delivery fee on a parcel that never arrived, a duplicate transfer, a
     * cancellation after payment.
     *
     * @throws ValidationException
     */
    private function resolveReturn(Order $order, ?int $returnId): ?OrderReturn
    {
        if ($returnId === null) {
            return null;
        }

        $return = OrderReturn::withoutGlobalScope(BranchScope::class)
            ->with('items.orderItem')
            ->whereKey($returnId)
            ->where('order_id', $order->getKey())
            ->first();

        if (! $return instanceof OrderReturn) {
            throw ValidationException::withMessages([
                'return_id' => 'Retur yang dipilih bukan milik pesanan ini.',
            ]);
        }

        $blocked = $return->refundBlockedReason();

        if ($blocked !== null) {
            throw ValidationException::withMessages(['return_id' => $blocked]);
        }

        return $return;
    }

    /**
     * The attempt the money came in on.
     *
     * Required for a gateway refund and optional otherwise: a provider will
     * only reverse a transaction it knows about, while a bank transfer made by
     * hand needs nothing but somebody's bank app. A payment that did not
     * succeed is refused outright — there is nothing of it to send back.
     *
     * @throws ValidationException
     */
    private function resolvePayment(Order $order, ?int $paymentId, RefundMethod $method): ?Payment
    {
        if ($paymentId === null) {
            if ($method->isAutomatic()) {
                throw ValidationException::withMessages([
                    'payment_id' => 'Pilih pembayaran yang akan dikembalikan lewat payment gateway.',
                ]);
            }

            return null;
        }

        $payment = Payment::withoutGlobalScope(BranchScope::class)
            ->with('paymentMethod')
            ->whereKey($paymentId)
            ->where('order_id', $order->getKey())
            ->first();

        if (! $payment instanceof Payment) {
            throw ValidationException::withMessages([
                'payment_id' => 'Pembayaran yang dipilih bukan milik pesanan ini.',
            ]);
        }

        if ($payment->status !== PaymentTransactionStatus::Success && $method->isAutomatic()) {
            throw ValidationException::withMessages([
                'payment_id' => "Pembayaran berstatus \"{$payment->status->label()}\" tidak dapat dikembalikan "
                    .'lewat payment gateway.',
            ]);
        }

        return $payment;
    }

    /**
     * Write the refund, with the amount checked under the order's row lock.
     *
     * @param  array{amount: int, method: RefundMethod, return_id: int|null, payment_id: int|null, account_info: string|null, reference: string|null, notes: string|null}  $data
     *
     * @throws ValidationException
     */
    private function open(
        Order $order,
        ?OrderReturn $return,
        ?Payment $payment,
        array $data,
        string $code,
        ?User $actor,
    ): Refund {
        return DB::transaction(function () use ($order, $return, $payment, $data, $code, $actor): Refund {
            $locked = $this->lockOrder($order);

            $remaining = $locked->refundableAmount();

            if ($data['amount'] > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => $remaining === 0
                        ? "Pesanan {$locked->order_number} sudah dikembalikan seluruhnya."
                        : 'Nominal pengembalian melebihi sisa dana pesanan, yaitu '
                            .number_format($remaining, 0, ',', '.').' rupiah.',
                ]);
            }

            return Refund::query()->create([
                'code' => $code,
                'order_id' => $locked->getKey(),
                'return_id' => $return?->getKey(),
                'payment_id' => $payment?->getKey(),
                'branch_id' => $locked->branch_id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'status' => RefundStatus::Pending,
                'account_info' => $data['account_info'],
                'notes' => $data['notes'],
                'created_by' => $actor?->getKey(),
            ]);
        });
    }

    /**
     * Ask the provider to reverse its own transaction, and record the answer.
     *
     * Outside any transaction on purpose — see the class docblock. The driver
     * never writes (ADR-004), so everything that lands comes from here.
     */
    private function carryOutThroughGateway(Refund $refund, ?Payment $payment, ?User $actor): Refund
    {
        if (! $payment instanceof Payment) {
            // Unreachable through `handle()`, which refuses a gateway refund
            // without a payment. Stated rather than assumed, because a null
            // here would otherwise be a type error at the gateway call.
            throw ValidationException::withMessages([
                'payment_id' => 'Pengembalian lewat payment gateway membutuhkan pembayaran asalnya.',
            ]);
        }

        try {
            $result = $this->gateways->forPayment($payment)->refund($payment, $refund->amount);
        } catch (PaymentFailedException $refused) {
            // `charge()` throws and `handleCallback()` does not (ADR-004);
            // `refund()` is allowed either, because a driver with no provider
            // to call cannot always answer in a value object. A refusal is
            // recorded on the row like any other, so the attempt stays
            // visible.
            return $this->recordFailed($refund, RefundResult::failed($refund->amount, $refused->getMessage()), $actor);
        }

        Log::info('Asked a payment gateway for a refund.', [
            'refund_id' => $refund->getKey(),
            'payment_id' => $payment->getKey(),
            'outcome' => $result->outcome,
            'amount' => $result->amount,
        ]);

        return match ($result->outcome) {
            RefundResult::OUTCOME_SETTLED => $this->recordSettled($refund, $result, $actor),
            RefundResult::OUTCOME_PENDING => $this->recordAccepted($refund, $result),
            RefundResult::OUTCOME_MANUAL => $this->recordNeedsHuman($refund, $result),
            default => $this->recordFailed($refund, $result, $actor),
        };
    }

    private function recordSettled(Refund $refund, RefundResult $result, ?User $actor): Refund
    {
        $this->settleRefund->handle($refund, $actor, $result->externalId);

        return $refund->refresh();
    }

    /**
     * The provider accepted the reversal but has not finished it.
     *
     * Left `pending` with the provider's id on it, so the callback — or
     * somebody checking the provider's dashboard — can finish it. Recording it
     * as settled here would claim money had moved on the word of a provider
     * that said the opposite.
     */
    private function recordAccepted(Refund $refund, RefundResult $result): Refund
    {
        $refund->update(['reference' => $result->externalId ?? $refund->reference]);

        return $refund->refresh();
    }

    /**
     * There is no provider to ask, so a person has to move the money.
     *
     * The driver's own sentence is kept as the note, because it is written for
     * the staff member who now has to act on it.
     */
    private function recordNeedsHuman(Refund $refund, RefundResult $result): Refund
    {
        $refund->update(['notes' => $result->reason ?? $refund->notes]);

        return $refund->refresh();
    }

    private function recordFailed(Refund $refund, RefundResult $result, ?User $actor): Refund
    {
        $refund->update([
            'status' => RefundStatus::Failed,
            'failure_reason' => $result->reason ?? 'Payment gateway menolak pengembalian dana.',
            'processed_by' => $actor?->getKey(),
            'processed_at' => now(),
        ]);

        return $refund->refresh();
    }

    /**
     * The order, re-read and locked for this transaction.
     *
     * Read across branches on purpose: a refund may be made by a central
     * actor, whose context does not narrow, and the key already identifies the
     * row. What the lock is for is the amount arithmetic.
     */
    private function lockOrder(Order $order): Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
