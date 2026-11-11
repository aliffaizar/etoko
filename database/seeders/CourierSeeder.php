<?php

namespace Database\Seeders;

use App\Enums\ShippingDriver;
use App\Enums\ShippingRateScope;
use App\Models\Courier;
use App\Models\CourierService;
use Illuminate\Database\Seeder;

/**
 * The couriers and the price list every environment needs (P2-E2-T1, T2).
 *
 * **Reference data, not demo content**, which is why it runs outside the
 * `isProduction()` guard in `DatabaseSeeder`: a shop with no courier has a
 * checkout whose delivery option cannot be completed, exactly as a shop with
 * no payment method has one whose payment cannot. It is the sibling of
 * `PaymentMethodSeeder` and follows the same rules.
 *
 * Safe to re-run. Each courier and service is matched on its code and its
 * *definition* is refreshed, but `is_active`, `sort_order` and `config` are
 * deliberately left alone once a row exists: those are the shop's own
 * decisions — which couriers it uses, in what order, and with which API key —
 * and re-seeding must not switch a disabled courier back on.
 *
 * **Rates are only created, never updated.** A price list is the one thing in
 * here a shop certainly edits, and re-running a seeder that overwrote it would
 * quietly restore the figures below over somebody's real tariff. A rate is
 * recognised by its shape — service, origin, destination — so re-running adds
 * nothing and changes nothing.
 *
 * The figures are **plausible placeholders, not real tariffs**, and the
 * per-kilogram structure mirrors how Indonesian couriers actually quote:
 * a flat price covering the first kilogram, then a charge per further
 * kilogram, rounded up. They are deliberately coarse — one rate per courier
 * for the whole country, plus a cheaper one for Java — rather than a detailed
 * table, because a made-up table that *reads* as real is one a shop would
 * trust without checking.
 *
 * The Biteship courier is seeded **inactive**: its driver needs an API key the
 * project does not have yet (`P2-E2-T6`), and an active courier whose provider
 * refuses every quote is a checkout that fails at the last step. Switching it
 * on is a matter of filling in `BITESHIP_API_KEY` and flipping `is_active`.
 */
class CourierSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->couriers() as $definition) {
            $services = $definition['services'];
            unset($definition['services']);

            $courier = $this->upsertCourier($definition);

            foreach ($services as $service) {
                $rates = $service['rates'] ?? [];
                unset($service['rates']);

                $this->seedRates($this->upsertService($courier, $service), $rates);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsertCourier(array $definition): Courier
    {
        $existing = Courier::query()->where('code', $definition['code'])->first();

        if ($existing instanceof Courier) {
            $existing->update($this->refreshable($definition));

            return $existing;
        }

        return Courier::query()->create($definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsertService(Courier $courier, array $definition): CourierService
    {
        $existing = $courier->services()->where('code', $definition['code'])->first();

        if ($existing instanceof CourierService) {
            $existing->update($this->refreshable($definition));

            return $existing;
        }

        /** @var CourierService $service */
        $service = $courier->services()->create($definition);

        return $service;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    private function seedRates(CourierService $service, array $rates): void
    {
        foreach ($rates as $rate) {
            $service->rates()->firstOrCreate(
                [
                    'destination_type' => $rate['destination_type'],
                    'destination_name' => $rate['destination_name'] ?? null,
                    'origin_branch_id' => null,
                ],
                $rate,
            );
        }
    }

    /**
     * The columns re-seeding may overwrite on a row that already exists.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function refreshable(array $definition): array
    {
        unset($definition['is_active'], $definition['sort_order'], $definition['config']);

        return $definition;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function couriers(): array
    {
        return [
            [
                'code' => 'jne',
                'name' => 'JNE',
                'driver' => ShippingDriver::Manual,
                'is_active' => true,
                'sort_order' => 1,
                'config' => ['tracking_url' => 'https://www.jne.co.id/id/tracking/trace'],
                'services' => [
                    [
                        'code' => 'REG',
                        'name' => 'Reguler',
                        'description' => 'Pengiriman standar, 2–3 hari kerja untuk kota besar.',
                        'etd_min_day' => 2,
                        'etd_max_day' => 3,
                        'is_active' => true,
                        'sort_order' => 1,
                        'rates' => [
                            $this->nationalRate(18_000, 9_000),
                            $this->provinceRate('DKI Jakarta', 12_000, 6_000),
                            $this->provinceRate('Jawa Barat', 14_000, 7_000),
                        ],
                    ],
                    [
                        'code' => 'YES',
                        'name' => 'YES (Besok Sampai)',
                        'description' => 'Sampai keesokan hari untuk kota yang dilayani.',
                        'etd_min_day' => 1,
                        'etd_max_day' => 1,
                        'is_active' => true,
                        'sort_order' => 2,
                        'rates' => [
                            $this->nationalRate(32_000, 15_000),
                            $this->provinceRate('DKI Jakarta', 24_000, 11_000),
                        ],
                    ],
                ],
            ],
            [
                'code' => 'jnt',
                'name' => 'J&T Express',
                'driver' => ShippingDriver::Manual,
                'is_active' => true,
                'sort_order' => 2,
                'config' => ['tracking_url' => 'https://www.jet.co.id/track'],
                'services' => [
                    [
                        'code' => 'EZ',
                        'name' => 'Reguler (EZ)',
                        'description' => 'Pengiriman standar, 2–4 hari kerja.',
                        'etd_min_day' => 2,
                        'etd_max_day' => 4,
                        'is_active' => true,
                        'sort_order' => 1,
                        'rates' => [
                            $this->nationalRate(16_000, 8_000),
                            $this->provinceRate('DKI Jakarta', 11_000, 5_500),
                            $this->provinceRate('Jawa Timur', 13_000, 6_500),
                        ],
                    ],
                ],
            ],
            [
                'code' => 'sicepat',
                'name' => 'SiCepat',
                'driver' => ShippingDriver::Manual,
                'is_active' => true,
                'sort_order' => 3,
                'config' => null,
                'services' => [
                    [
                        'code' => 'REG',
                        'name' => 'Reguler',
                        'description' => 'Pengiriman standar, 2–3 hari kerja.',
                        'etd_min_day' => 2,
                        'etd_max_day' => 3,
                        'is_active' => true,
                        'sort_order' => 1,
                        'rates' => [$this->nationalRate(17_000, 8_500)],
                    ],
                    [
                        'code' => 'SDS',
                        'name' => 'SDS (Sampai Hari Ini)',
                        'description' => 'Sampai di hari yang sama, hanya dalam kota.',
                        'etd_min_day' => null,
                        'etd_max_day' => 1,
                        'is_active' => true,
                        'sort_order' => 2,
                        // Same-city only, so there is no national rate: an
                        // address outside these cities simply gets no SDS
                        // option, which is the honest answer rather than a
                        // same-day promise to another island.
                        'rates' => [
                            $this->cityRate('Jakarta Pusat', 28_000),
                            $this->cityRate('Bandung', 26_000),
                            $this->cityRate('Surabaya', 26_000),
                        ],
                    ],
                ],
            ],
            [
                'code' => 'biteship',
                'name' => 'Biteship (Agregator)',
                'driver' => ShippingDriver::Biteship,
                // Inactive until BITESHIP_API_KEY exists — see the class
                // docblock.
                'is_active' => false,
                'sort_order' => 9,
                'config' => ['biteship' => ['courier_code' => 'jne']],
                'services' => [
                    [
                        'code' => 'REG',
                        'name' => 'Reguler (via Biteship)',
                        'description' => 'Tarif diambil langsung dari Biteship.',
                        'etd_min_day' => 2,
                        'etd_max_day' => 3,
                        'is_active' => true,
                        'sort_order' => 1,
                        // No rates: an aggregator quotes its own prices, and a
                        // fallback row here would be a figure the shop invented
                        // for a courier that is meant to answer for itself.
                        'rates' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * The catch-all rule: anywhere in the country, from any branch.
     *
     * @return array<string, mixed>
     */
    private function nationalRate(int $flat, int $perKilogram): array
    {
        return [
            'destination_type' => ShippingRateScope::All,
            'destination_name' => null,
            'flat_price' => $flat,
            'price_per_kg' => $perKilogram,
            'min_weight_gram' => 1_000,
            'is_active' => true,
        ];
    }

    /**
     * A cheaper rule for one province, which beats the national one.
     *
     * @return array<string, mixed>
     */
    private function provinceRate(string $province, int $flat, int $perKilogram): array
    {
        return [
            'destination_type' => ShippingRateScope::Province,
            'destination_name' => $province,
            'flat_price' => $flat,
            'price_per_kg' => $perKilogram,
            'min_weight_gram' => 1_000,
            'is_active' => true,
        ];
    }

    /**
     * A flat rule for one city, at any weight — how same-day couriers quote.
     *
     * @return array<string, mixed>
     */
    private function cityRate(string $city, int $flat): array
    {
        return [
            'destination_type' => ShippingRateScope::City,
            'destination_name' => $city,
            'flat_price' => $flat,
            'price_per_kg' => 0,
            'min_weight_gram' => 1_000,
            'is_active' => true,
        ];
    }
}
