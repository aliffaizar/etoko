<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live redemption. It does **not** move `vouchers.used_count` — only
 * `RedeemVoucher` keeps the two together — so a test that needs a used quota
 * sets it with `VoucherFactory::quota()` as well.
 *
 * @extends Factory<VoucherUsage>
 */
class VoucherUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'voucher_id' => Voucher::factory(),
            'user_id' => User::factory()->customer(),
            'order_id' => fn (array $attributes) => Order::factory()
                ->forBranch(Branch::factory()->create())
                ->create(['user_id' => $attributes['user_id']]),
            'discount_amount' => 10_000,
            'used_at' => now(),
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(['released_at' => now()]);
    }
}
