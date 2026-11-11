<?php

namespace Database\Factories;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * A refund somebody still has to carry out, for the whole order.
     *
     * `branch_id` and `amount` are derived from the order through closures
     * over `$attributes`, so a caller who passes only `forOrder()` gets a row
     * whose branch and figure agree with it — and the branch has to agree, or
     * the branch scope would hide the refund from the branch whose order it
     * is (ADR-038).
     *
     * `status` stays `pending`: a `processed` row is money the application
     * says has gone back, which moves `orders.payment_status` with it, and
     * only `ProcessRefund` may do that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fn (): string => DocumentNumber::next(DocumentNumber::PREFIX_REFUND),
            'order_id' => Order::factory(),
            'return_id' => null,
            'payment_id' => null,
            'branch_id' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->branch_id,
            'amount' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->grand_total,
            'method' => RefundMethod::Transfer,
            'status' => RefundStatus::Pending,
            'reference' => null,
            'account_info' => 'BCA 1234567890 a/n Budi Santoso',
            'failure_reason' => null,
            'notes' => null,
            'created_by' => null,
            'processed_by' => null,
            'processed_at' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->getKey(),
            'branch_id' => $order->branch_id,
            'amount' => $order->grand_total,
        ]);
    }

    public function forReturn(OrderReturn $return): static
    {
        return $this->state([
            'return_id' => $return->getKey(),
            'order_id' => $return->order_id,
            'branch_id' => $return->branch_id,
        ]);
    }

    public function amount(int $amount): static
    {
        return $this->state(['amount' => $amount]);
    }

    public function method(RefundMethod $method): static
    {
        return $this->state(['method' => $method]);
    }

    /**
     * Money the record says has gone back, as a *record* only.
     *
     * The order it belongs to is left exactly as it is, so this state is for
     * tests about reading refunds, never about what processing one does.
     */
    public function processed(): static
    {
        return $this->state([
            'status' => RefundStatus::Processed,
            'reference' => mb_strtoupper(fake()->bothify('TRF########')),
            'processed_by' => User::factory()->adminPusat(),
            'processed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Rekening tujuan tidak valid.'): static
    {
        return $this->state([
            'status' => RefundStatus::Failed,
            'failure_reason' => $reason,
            'processed_by' => User::factory()->adminPusat(),
            'processed_at' => now(),
        ]);
    }
}
