<?php

namespace Database\Factories;

use App\Enums\StockDocumentStatus;
use App\Enums\StockIssueReason;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockIssue>
 */
class StockIssueFactory extends Factory
{
    /**
     * A draft note with no lines yet.
     *
     * The reason defaults to damage rather than "other", because "other"
     * additionally requires notes and would make the simplest factory call
     * invalid against the form's rules.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => DocumentNumber::next(DocumentNumber::PREFIX_STOCK_ISSUE),
            'warehouse_id' => Warehouse::factory(),
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'reason' => StockIssueReason::Damage,
            'reference_no' => null,
            'issue_date' => now()->toDateString(),
            'status' => StockDocumentStatus::Draft,
            'notes' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    public function inWarehouse(Warehouse $warehouse): static
    {
        return $this->state([
            'warehouse_id' => $warehouse->getKey(),
            'branch_id' => $warehouse->branch_id,
        ]);
    }

    public function because(StockIssueReason $reason): static
    {
        return $this->state(['reason' => $reason]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => StockDocumentStatus::Cancelled]);
    }
}
