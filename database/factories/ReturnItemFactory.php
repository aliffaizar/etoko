<?php

namespace Database\Factories;

use App\Enums\ReturnItemCondition;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnItem>
 */
class ReturnItemFactory extends Factory
{
    /**
     * One line asking for every unit of its order line back.
     *
     * `quantity` is derived from the order line through a closure over
     * `$attributes` rather than invented, because a return line for more units
     * than were ever bought is a row no validator in the application would
     * have let through, and a test built on one proves nothing.
     *
     * `restocked_at` is **not** faked: units are only ever on a shelf because
     * `StockLedger` put them there (ADR-024).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_id' => OrderReturn::factory(),
            'order_item_id' => OrderItem::factory(),
            'quantity' => fn (array $attributes): int => OrderItem::query()
                ->whereKey($attributes['order_item_id'])
                ->sole()
                ->quantity,
            'condition' => ReturnItemCondition::Good,
            'restock' => true,
            'restock_warehouse_id' => null,
            'restocked_at' => null,
            'notes' => null,
        ];
    }

    public function forReturn(OrderReturn $return): static
    {
        return $this->state(['return_id' => $return->getKey()]);
    }

    public function forOrderItem(OrderItem $item, ?int $quantity = null): static
    {
        return $this->state([
            'order_item_id' => $item->getKey(),
            'quantity' => $quantity ?? $item->quantity,
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(['quantity' => $quantity]);
    }

    public function damaged(): static
    {
        return $this->state([
            'condition' => ReturnItemCondition::Damaged,
            'restock' => false,
        ]);
    }

    public function withoutRestock(): static
    {
        return $this->state(['restock' => false]);
    }
}
