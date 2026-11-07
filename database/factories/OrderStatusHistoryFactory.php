<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    /**
     * The opening row of a timeline: an order coming into existence, which has
     * no status to come from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'note' => null,
            'changed_by' => null,
            'created_at' => now(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(['order_id' => $order->getKey()]);
    }

    public function transition(OrderStatus $from, OrderStatus $to): static
    {
        return $this->state(['from_status' => $from, 'to_status' => $to]);
    }

    public function by(User $user): static
    {
        return $this->state(['changed_by' => $user->getKey()]);
    }
}
