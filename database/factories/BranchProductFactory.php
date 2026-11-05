<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchProduct>
 */
class BranchProductFactory extends Factory
{
    /**
     * Defaults to `is_available = false`, because that is the only reason a row
     * exists at all: a branch that sells a product stores nothing (P1-E3-T9).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'is_available' => false,
            'notes' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(['is_available' => true]);
    }

    public function withNotes(string $notes): static
    {
        return $this->state(['notes' => $notes]);
    }
}
