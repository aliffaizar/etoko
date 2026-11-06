<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_token' => null,
            'branch_id' => null,
            'fulfillment_type' => null,
            'expires_at' => null,
        ];
    }

    public function guest(): static
    {
        return $this->state([
            'user_id' => null,
            'session_token' => Str::uuid()->toString(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey(), 'session_token' => null]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->getKey()]);
    }
}
