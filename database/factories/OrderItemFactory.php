<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * One line with its snapshots filled in and `subtotal` agreeing with
     * `unit_price × quantity − discount_amount`.
     *
     * `forVariant()` copies the snapshots from the real variant, which is what
     * `PlaceOrder` does; without it the line is self-consistent but describes a
     * product nobody can look up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(10, 500) * 1_000;
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_variant_id' => null,
            'sku' => mb_strtoupper(fake()->bothify('SKU-####-??')),
            'product_name' => fake()->words(3, true),
            'variant_name' => null,
            'image_path' => null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'discount_amount' => 0,
            'subtotal' => $unitPrice * $quantity,
            'weight_gram' => fake()->numberBetween(100, 2_000),
            'cost_price' => null,
            'refunded_quantity' => 0,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(['order_id' => $order->getKey()]);
    }

    /**
     * Take the line's snapshots from a real variant, the way `PlaceOrder` does.
     */
    public function forVariant(ProductVariant $variant): static
    {
        $variant->loadMissing('product');

        return $this->state(fn (array $attributes): array => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'sku' => $variant->sku,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
            'unit_price' => $variant->effectivePrice(),
            'subtotal' => $variant->effectivePrice() * $attributes['quantity'] - $attributes['discount_amount'],
            'weight_gram' => $variant->effectiveWeightGram(),
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => $quantity,
            'subtotal' => $attributes['unit_price'] * $quantity - $attributes['discount_amount'],
        ]);
    }

    public function unitPrice(int $unitPrice): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $attributes['quantity'] - $attributes['discount_amount'],
        ]);
    }
}
