<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price_snapshot' => fake()->numberBetween(10_000, 500_000),
            'notes' => null,
        ];
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(['cart_id' => $cart->getKey()]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        // `effectivePrice()` reads through to the product, and
        // `Model::preventLazyLoading()` is on outside production.
        $variant->loadMissing('product');

        return $this->state([
            'product_variant_id' => $variant->getKey(),
            'unit_price_snapshot' => $variant->effectivePrice(),
        ]);
    }
}
