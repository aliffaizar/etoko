<?php

namespace Database\Factories;

use App\Enums\PaymentTransactionStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * A pending manual transfer, which is what `InitiatePayment` produces for
     * the method most of this epic is about.
     *
     * `branch_id`, `amount` and the two method snapshots are all derived from
     * the rows this payment points at, through closures over `$attributes`:
     * a caller who passes only `forOrder()` still gets a row whose branch and
     * total agree with that order, and `payments.branch_id` must agree with
     * `orders.branch_id` or the branch scope would hide a payment from the
     * branch that has to verify it (ADR-038).
     *
     * `paid_at`, `verified_by` and the holds that a successful payment commits
     * are **not** faked: marking money as received takes goods off the shelf,
     * which only `VerifyPayment` may do (ADR-024). A test that needs a settled
     * payment settles one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->branch_id,
            'payment_method_id' => PaymentMethod::factory(),
            'method_name' => fn (array $attributes): string => PaymentMethod::query()
                ->whereKey($attributes['payment_method_id'])
                ->sole()
                ->name,
            'method_type' => fn (array $attributes): string => PaymentMethod::query()
                ->whereKey($attributes['payment_method_id'])
                ->sole()
                ->type
                ->value,
            'amount' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->grand_total,
            'fee_amount' => 0,
            'status' => PaymentTransactionStatus::Pending,
            'reference' => fn (array $attributes): string => Payment::newReferenceFor(
                Order::withoutGlobalScopes()->whereKey($attributes['order_id'])->sole(),
            ),
            'external_id' => null,
            'va_number' => null,
            'qr_string' => null,
            'payment_url' => null,
            'proof_path' => null,
            'proof_note' => null,
            'proof_uploaded_at' => null,
            'paid_at' => null,
            'expires_at' => now()->addMinutes(15),
            'failure_reason' => null,
            'verified_by' => null,
            'verified_at' => null,
            'raw_payload' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->getKey(),
            'branch_id' => $order->branch_id,
            'amount' => $order->grand_total,
            'reference' => Payment::newReferenceFor($order),
        ]);
    }

    public function forMethod(PaymentMethod $method): static
    {
        return $this->state([
            'payment_method_id' => $method->getKey(),
            'method_name' => $method->name,
            'method_type' => $method->type->value,
        ]);
    }

    /**
     * A receipt the customer has uploaded but nobody has looked at yet — the
     * row the verification queue in `P2-E1-T4` exists for.
     */
    public function withProof(string $path = 'payment-proofs/bukti.jpg'): static
    {
        return $this->state([
            'proof_path' => $path,
            'proof_note' => 'Transfer dari BCA a/n Budi Santoso',
            'proof_uploaded_at' => now(),
        ]);
    }

    /**
     * A settled attempt, as a *record* only.
     *
     * The order it belongs to is left exactly as it is, so this state is for
     * tests about reading payments, never about what settling one does.
     */
    public function success(): static
    {
        return $this->state([
            'status' => PaymentTransactionStatus::Success,
            'paid_at' => now(),
            'verified_by' => User::factory()->adminPusat(),
            'verified_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Nominal transfer tidak sesuai.'): static
    {
        return $this->state([
            'status' => PaymentTransactionStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => PaymentTransactionStatus::Expired,
            'expires_at' => now()->subMinute(),
            'failure_reason' => 'Batas waktu pembayaran terlewat.',
        ]);
    }

    /**
     * An attempt whose deadline has already passed while it is still pending —
     * what the sweep in `P2-E1-T3` is looking for.
     */
    public function overrun(): static
    {
        return $this->state([
            'status' => PaymentTransactionStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function expiringAt(?\DateTimeInterface $moment): static
    {
        return $this->state(['expires_at' => $moment]);
    }
}
