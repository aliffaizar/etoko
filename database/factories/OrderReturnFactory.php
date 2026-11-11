<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderReturn>
 */
class OrderReturnFactory extends Factory
{
    /**
     * A request a customer has just made, with nothing decided yet.
     *
     * `branch_id` and `user_id` are derived from the order through closures
     * over `$attributes`, so a caller who passes only `forOrder()` still gets
     * a row whose branch agrees with that order — and it has to, or the branch
     * scope would hide the request from the branch that has to decide it
     * (ADR-038).
     *
     * `approved_at`, `received_at` and the stock that receiving puts back are
     * **not** faked: booking goods in moves `stocks`, which only
     * `ReceiveReturn` may do through `StockLedger` (ADR-024). A test that
     * needs goods back puts them back.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fn (): string => DocumentNumber::next(DocumentNumber::PREFIX_RETURN),
            'order_id' => Order::factory(),
            'user_id' => fn (array $attributes): ?int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->user_id,
            'branch_id' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->branch_id,
            'type' => ReturnType::Refund,
            'reason' => fake()->randomElement([
                ReturnReason::Damaged,
                ReturnReason::WrongItem,
                ReturnReason::NotAsDescribed,
            ]),
            'description' => fake()->sentence(),
            'photo_paths' => null,
            'status' => ReturnStatus::Requested,
            'requested_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'received_by' => null,
            'received_at' => null,
            'completed_at' => null,
            'rejection_reason' => null,
            'notes' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->getKey(),
            'branch_id' => $order->branch_id,
            'user_id' => $order->user_id,
        ]);
    }

    public function reason(ReturnReason $reason): static
    {
        return $this->state(['reason' => $reason]);
    }

    public function exchange(): static
    {
        return $this->state(['type' => ReturnType::Exchange]);
    }

    /**
     * A request the branch has agreed to take back, as a *record* only.
     *
     * No goods have moved: approval is a promise, and the stock stays where it
     * is until `ReceiveReturn` books the parcel in.
     */
    public function approved(?User $approver = null): static
    {
        return $this->state([
            'status' => ReturnStatus::Approved,
            'approved_by' => $approver?->getKey() ?? User::factory()->adminCabang(),
            'approved_at' => now(),
        ]);
    }

    /**
     * A request whose parcel is marked as arrived, as a *record* only.
     *
     * The lines' `restocked_at` and the ledger rows that go with it are
     * deliberately absent: this state is for tests about reading a received
     * return or refunding against one, never about what receiving does.
     */
    public function received(?User $receiver = null): static
    {
        return $this->approved($receiver)->state([
            'status' => ReturnStatus::Received,
            'received_by' => $receiver?->getKey() ?? User::factory()->adminCabang(),
            'received_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Barang sudah melewati masa retur.'): static
    {
        return $this->state([
            'status' => ReturnStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => ReturnStatus::Cancelled]);
    }

    public function completed(): static
    {
        return $this->received()->state([
            'status' => ReturnStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
