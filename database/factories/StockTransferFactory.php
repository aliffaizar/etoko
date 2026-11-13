<?php

namespace Database\Factories;

use App\Enums\StockTransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    /**
     * A request with no lines yet, between two warehouses of two new branches.
     *
     * The branch ids follow the warehouses through closures over
     * `$attributes`, so a caller that names only the warehouses gets branch
     * ids that agree with them (docs/06-conventions.md, BUG-08).
     *
     * Only the status is set by the step states below; the quantities on the
     * lines are the caller's to state, because a factory that invented them
     * would be inventing ledger rows that never happened.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => DocumentNumber::next(DocumentNumber::PREFIX_STOCK_TRANSFER),
            'from_warehouse_id' => Warehouse::factory(),
            'from_branch_id' => fn (array $attributes): int => $this->branchOf($attributes['from_warehouse_id']),
            'to_warehouse_id' => Warehouse::factory(),
            'to_branch_id' => fn (array $attributes): int => $this->branchOf($attributes['to_warehouse_id']),
            'status' => StockTransferStatus::Requested,
            'notes' => null,
            'requested_by' => User::factory(),
            'requested_at' => now(),
        ];
    }

    public function between(Warehouse $from, Warehouse $to): static
    {
        return $this->state([
            'from_warehouse_id' => $from->getKey(),
            'from_branch_id' => $from->branch_id,
            'to_warehouse_id' => $to->getKey(),
            'to_branch_id' => $to->branch_id,
        ]);
    }

    public function approved(): static
    {
        return $this->state(['status' => StockTransferStatus::Approved, 'approved_at' => now()]);
    }

    public function inTransit(): static
    {
        return $this->state([
            'status' => StockTransferStatus::InTransit,
            'approved_at' => now(),
            'shipped_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => StockTransferStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => 'Stok sedang dipakai untuk promo.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => StockTransferStatus::Cancelled, 'cancelled_at' => now()]);
    }

    private function branchOf(mixed $warehouseId): int
    {
        return Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->sole()->branch_id;
    }
}
