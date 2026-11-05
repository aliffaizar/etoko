<?php

namespace Database\Factories;

use App\Enums\StockDocumentStatus;
use App\Models\StockEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockEntry>
 */
class StockEntryFactory extends Factory
{
    /**
     * A draft note with no lines yet, which is what "Tambah Barang Masuk"
     * produces.
     *
     * Posting is not a state this factory can fake: it has to move stock and
     * write the ledger, so a test that needs a posted document posts one
     * through `PostStockEntry`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => DocumentNumber::next(DocumentNumber::PREFIX_STOCK_ENTRY),
            'warehouse_id' => Warehouse::factory(),
            'branch_id' => fn (array $attributes): int => Warehouse::withoutGlobalScopes()
                ->whereKey($attributes['warehouse_id'])
                ->sole()
                ->branch_id,
            'supplier_name' => fake()->company(),
            'reference_no' => fake()->bothify('DO-####'),
            'entry_date' => now()->toDateString(),
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

    public function cancelled(): static
    {
        return $this->state(['status' => StockDocumentStatus::Cancelled]);
    }
}
