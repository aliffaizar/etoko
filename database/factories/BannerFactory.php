<?php

namespace Database\Factories;

use App\Enums\BannerPosition;
use App\Models\Banner;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A running homepage banner shown at every branch, with no period — the states
 * narrow it.
 *
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'subtitle' => fake()->sentence(6),
            'image_path' => null,
            'mobile_image_path' => null,
            'link_url' => null,
            'position' => BannerPosition::Hero,
            'branch_id' => null,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->getKey()]);
    }

    public function between(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function sortOrder(int $order): static
    {
        return $this->state(['sort_order' => $order]);
    }
}
