<?php

namespace Database\Factories;

use App\Enums\StockOpnameStatus;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpname>
 */
class StockOpnameFactory extends Factory
{
    /**
     * A drafted session with no lines yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => DocumentNumber::next(DocumentNumber::PREFIX_STOCK_OPNAME),
            'warehouse_id' => Warehouse::factory(),
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'status' => StockOpnameStatus::Draft,
            'counted_at' => now()->toDateString(),
            'notes' => null,
            'created_by' => User::factory(),
            'approved_by' => null,
            'completed_at' => null,
        ];
    }

    public function inWarehouse(Warehouse $warehouse): static
    {
        return $this->state([
            'warehouse_id' => $warehouse->getKey(),
            'branch_id' => $warehouse->branch_id,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => StockOpnameStatus::InProgress]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => StockOpnameStatus::Cancelled]);
    }
}
