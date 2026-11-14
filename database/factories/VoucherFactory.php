<?php

namespace Database\Factories;

use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A running 10%-off voucher for every product, every branch, once per
 * customer, no quota and no minimum — the states narrow it.
 *
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'V'.Str::upper(Str::random(7)),
            'name' => fake()->words(3, true),
            'description' => null,
            'type' => VoucherType::Percentage,
            'value' => 10,
            'max_discount' => null,
            'min_order_amount' => 0,
            'quota' => null,
            'used_count' => 0,
            'per_user_limit' => 1,
            'applies_to' => VoucherAppliesTo::All,
            'channel' => VoucherChannel::All,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function percentage(int $percent, ?int $maxDiscount = null): static
    {
        return $this->state([
            'type' => VoucherType::Percentage,
            'value' => $percent,
            'max_discount' => $maxDiscount,
        ]);
    }

    public function fixed(int $amount): static
    {
        return $this->state([
            'type' => VoucherType::Fixed,
            'value' => $amount,
            'max_discount' => null,
        ]);
    }

    public function freeShipping(?int $maxDiscount = null): static
    {
        return $this->state([
            'type' => VoucherType::FreeShipping,
            'value' => 0,
            'max_discount' => $maxDiscount,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function scheduled(): static
    {
        return $this->state([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(10),
        ]);
    }

    public function ended(): static
    {
        return $this->state([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function quota(int $quota, int $used = 0): static
    {
        return $this->state(['quota' => $quota, 'used_count' => $used]);
    }

    public function perUser(int $limit): static
    {
        return $this->state(['per_user_limit' => $limit]);
    }

    public function minimum(int $amount): static
    {
        return $this->state(['min_order_amount' => $amount]);
    }

    public function channel(VoucherChannel $channel): static
    {
        return $this->state(['channel' => $channel]);
    }

    public function atBranches(Branch ...$branches): static
    {
        return $this->afterCreating(
            fn (Voucher $voucher) => $voucher->branches()->sync(array_map(fn (Branch $b): int => $b->getKey(), $branches)),
        );
    }

    public function forCategories(Category ...$categories): static
    {
        return $this->state(['applies_to' => VoucherAppliesTo::Category])->afterCreating(
            fn (Voucher $voucher) => $voucher->categories()->sync(array_map(fn (Category $c): int => $c->getKey(), $categories)),
        );
    }

    public function forProducts(Product ...$products): static
    {
        return $this->state(['applies_to' => VoucherAppliesTo::Product])->afterCreating(
            fn (Voucher $voucher) => $voucher->products()->sync(array_map(fn (Product $p): int => $p->getKey(), $products)),
        );
    }
}
