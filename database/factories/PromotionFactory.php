<?php

namespace Database\Factories;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A running 10%-off promotion with no period and no branch narrowing — the
 * states and the attach calls narrow it.
 *
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'type' => PromotionType::Percentage,
            'value' => 10,
            'priority' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function percentage(int $percent): static
    {
        return $this->state(['type' => PromotionType::Percentage, 'value' => $percent]);
    }

    public function fixed(int $amount): static
    {
        return $this->state(['type' => PromotionType::Fixed, 'value' => $amount]);
    }

    public function priority(int $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function between(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
