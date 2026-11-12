<?php

namespace Database\Factories;

use App\Enums\PaymentDriver;
use App\Enums\PaymentMethodType;
use App\Models\Branch;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * A plain manual bank transfer with no fence around it — the method most
     * tests want, because it is the one the whole `P2-E1-T3` flow is about.
     *
     * `code` is derived from the final `name` through a closure over
     * `$attributes` rather than from a local variable, so a caller who
     * overrides only the name still gets a matching code (the rule BUG-08
     * bought).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Transfer '.fake()->unique()->randomElement(['BCA', 'BNI', 'BRI', 'Mandiri', 'Permata', 'CIMB']),
            'code' => fn (array $attributes): string => Str::slug((string) $attributes['name']).'-'.fake()->unique()->numerify('####'),
            'type' => PaymentMethodType::BankTransfer,
            'driver' => PaymentDriver::Manual,
            'provider_code' => null,
            'instructions' => 'Transfer tepat sesuai nominal, lalu unggah bukti transfer.',
            'logo_path' => null,
            'fee_flat' => 0,
            'fee_percent' => 0,
            'min_amount' => null,
            'max_amount' => null,
            'is_active' => true,
            'sort_order' => 0,
            'config' => [
                'bank' => [
                    'name' => 'BCA',
                    'account_number' => '1234567890',
                    'account_holder' => 'PT Etoko Nusantara',
                ],
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Cash on delivery: a delivery-only method with no bank account behind it.
     */
    public function cod(): static
    {
        return $this->state([
            'name' => 'Bayar di Tempat (COD)',
            'type' => PaymentMethodType::Cod,
            'driver' => PaymentDriver::Manual,
            'instructions' => 'Siapkan uang tunai sesuai total pesanan saat kurir tiba.',
            'config' => ['fulfillment_types' => ['delivery']],
        ]);
    }

    /**
     * Cash at the till: never offered in the storefront.
     */
    public function cash(): static
    {
        return $this->state([
            'name' => 'Tunai',
            'type' => PaymentMethodType::Cash,
            'driver' => PaymentDriver::Manual,
            'instructions' => null,
            'config' => null,
        ]);
    }

    /**
     * A gateway-backed method, which is what `P2-E1-T5` and `P2-E1-T6` exercise.
     */
    public function midtrans(PaymentMethodType $type = PaymentMethodType::VirtualAccount): static
    {
        return $this->state([
            'name' => 'Midtrans '.$type->label(),
            'type' => $type,
            'driver' => PaymentDriver::Midtrans,
            'provider_code' => match ($type) {
                PaymentMethodType::VirtualAccount => 'bca',
                PaymentMethodType::EWallet => 'gopay',
                PaymentMethodType::Qris => 'qris',
                default => null,
            },
            'instructions' => null,
            'config' => null,
        ]);
    }

    /**
     * A method fenced to the given branches (`P2-E1-T7`).
     */
    public function forBranches(Branch ...$branches): static
    {
        return $this->state(fn (array $attributes): array => [
            'config' => [
                ...(is_array($attributes['config'] ?? null) ? $attributes['config'] : []),
                'branch_ids' => array_map(fn (Branch $branch): int => $branch->getKey(), $branches),
            ],
        ]);
    }

    /**
     * A method fenced to the given cities (`P2-E1-T7`).
     */
    public function forCities(string ...$cities): static
    {
        return $this->state(fn (array $attributes): array => [
            'config' => [
                ...(is_array($attributes['config'] ?? null) ? $attributes['config'] : []),
                'cities' => array_values($cities),
            ],
        ]);
    }

    /**
     * A method that only accepts orders inside the given range.
     */
    public function betweenAmounts(?int $min, ?int $max): static
    {
        return $this->state(['min_amount' => $min, 'max_amount' => $max]);
    }

    public function withFee(int $flat, float $percent = 0): static
    {
        return $this->state(['fee_flat' => $flat, 'fee_percent' => $percent]);
    }
}
