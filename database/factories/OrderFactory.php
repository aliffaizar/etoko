<?php

namespace Database\Factories;

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * A freshly placed delivery order awaiting payment — what checkout
     * produces (P1-E7-T5).
     *
     * The money columns are consistent with each other but carry **no lines**:
     * the totals an order was built from come from its items, and a factory
     * that invented both would let the two drift. A test that needs real
     * figures adds items with `OrderItem::factory()` and calls
     * `recalculatedTotals()`, or places an order through `PlaceOrder`.
     *
     * Reserved stock is not faked either: holding stock means writing
     * `stocks.reserved_quantity` and a `stock_reservations` row together, which
     * only `ReserveStock` may do (ADR-024).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(50, 2_000) * 1_000;
        $shipping = 15_000;

        return [
            'order_number' => DocumentNumber::next(DocumentNumber::PREFIX_ORDER),
            'user_id' => User::factory()->customer(),
            'branch_id' => Branch::factory(),
            'channel' => OrderChannel::Online,
            'fulfillment_type' => FulfillmentType::Delivery,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Unpaid,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->numerify('08##########'),
            'shipping_recipient' => fake()->name(),
            'shipping_phone' => fake()->numerify('08##########'),
            'shipping_address_line' => fake()->streetAddress(),
            'shipping_district_name' => fake()->citySuffix(),
            'shipping_city_name' => fake()->city(),
            'shipping_province_name' => 'DKI Jakarta',
            'shipping_postal_code' => fake()->numerify('#####'),
            'shipping_latitude' => null,
            'shipping_longitude' => null,
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'shipping_cost' => $shipping,
            'tax_total' => 0,
            'grand_total' => $subtotal + $shipping,
            'total_weight_gram' => fake()->numberBetween(100, 5_000),
            'voucher_id' => null,
            'voucher_code' => null,
            'customer_note' => null,
            'internal_note' => null,
            'placed_at' => now(),
            'created_by' => null,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->getKey()]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state([
            'user_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
        ]);
    }

    /**
     * A guest / walk-in order: no account behind it, only the snapshot.
     */
    public function guest(): static
    {
        return $this->state(['user_id' => null]);
    }

    /**
     * Click & Collect: nothing is shipped, so the whole shipping snapshot is
     * null and the shipping cost is zero — the same shape `PlaceOrder` writes.
     */
    public function pickup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfillment_type' => FulfillmentType::Pickup,
            'shipping_recipient' => null,
            'shipping_phone' => null,
            'shipping_address_line' => null,
            'shipping_district_name' => null,
            'shipping_city_name' => null,
            'shipping_province_name' => null,
            'shipping_postal_code' => null,
            'shipping_cost' => 0,
            'grand_total' => $attributes['subtotal'] - $attributes['discount_total'] + $attributes['tax_total'],
        ]);
    }

    public function pos(): static
    {
        return $this->state(['channel' => OrderChannel::Pos]);
    }

    /**
     * Put the order in a given status, with the timestamps that status implies.
     *
     * Only the record is faked, not its consequences: a factory cannot move
     * stock. Tests about what a transition *does* drive it through
     * `TransitionOrderStatus`.
     */
    public function status(OrderStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];
            $column = Order::timestampColumnFor($status);

            if ($column !== null) {
                $state[$column] = now();
            }

            $afterPayment = [
                OrderStatus::Paid, OrderStatus::Processing, OrderStatus::ReadyForPickup,
                OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed,
            ];

            if (in_array($status, $afterPayment, strict: true)) {
                $state['payment_status'] = PaymentStatus::Paid;
                $state['paid_at'] = $attributes['paid_at'] ?? now();
            }

            return $state;
        });
    }

    public function paid(): static
    {
        return $this->status(OrderStatus::Paid);
    }

    public function processing(): static
    {
        return $this->status(OrderStatus::Processing);
    }

    /**
     * A Click & Collect order whose goods are waiting on the shelf, with a
     * collection code already issued (P2-E2-T7).
     *
     * The code is part of the *record*, which is why it belongs in a factory:
     * `TransitionOrderStatus` issues it on the way into this status, and a row
     * in this status without one is a state the application never writes. A
     * test about the issuing drives the transition instead.
     */
    public function readyForPickup(string $code = 'K7M2PQ'): static
    {
        return $this->pickup()
            ->status(OrderStatus::ReadyForPickup)
            ->state(['pickup_code' => $code]);
    }

    /**
     * An order whose goods have reached the customer.
     *
     * The state returns open from (`Order::returnBlockedReason()`): goods can
     * only come back once they have gone.
     */
    public function delivered(): static
    {
        return $this->status(OrderStatus::Delivered);
    }

    public function completed(): static
    {
        return $this->status(OrderStatus::Completed);
    }

    public function cancelled(?string $reason = null): static
    {
        return $this->state([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason ?? 'Dibatalkan pelanggan.',
        ]);
    }

    /**
     * Rewrite the money columns from the lines the order actually has.
     *
     * For tests that build an order out of `OrderItem::factory()` rows and then
     * need its header to agree with them.
     */
    public function recalculatedTotals(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $order->load('items');

            $subtotal = (int) $order->items->sum('subtotal');
            $weight = $order->items->sum(fn ($item): int => $item->totalWeightGram());

            $order->forceFill([
                'subtotal' => $subtotal,
                'total_weight_gram' => $weight,
                'grand_total' => $subtotal - $order->discount_total + $order->shipping_cost + $order->tax_total,
            ])->saveQuietly();
        });
    }
}
