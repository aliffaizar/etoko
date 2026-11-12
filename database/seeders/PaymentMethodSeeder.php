<?php

namespace Database\Seeders;

use App\Enums\PaymentDriver;
use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * The ways a customer may pay, as every environment needs them (P2-E1-T1).
 *
 * **Reference data, not demo content**, which is why it runs outside the
 * `isProduction()` guard in `DatabaseSeeder`: a shop with no payment methods
 * has a checkout that cannot be completed, so this is as much part of a usable
 * install as the role matrix.
 *
 * Safe to re-run. Each row is matched on its `code` and its *definition* is
 * refreshed, but three columns are deliberately left alone once the row exists:
 * `is_active`, `sort_order` and `config`. Those are the shop's own decisions —
 * which methods it offers, in what order, and where each one is fenced — and
 * re-seeding must not switch a disabled method back on.
 *
 * **One row per Midtrans channel** (ADR-053). The charge goes through the Core
 * API, which is asked for one specific channel rather than shown a menu, so
 * the choice of bank or wallet is made at our checkout: `type` plus
 * `provider_code` is what `MidtransGateway` turns into a `payment_type`.
 *
 * **Online payment goes through Midtrans, and only Midtrans** (ADR-051).
 * There are no manual bank transfer rows here: the shop asked for one payment
 * path rather than two, and a transfer the shop has to match against its own
 * bank statement is the expensive one to run. What stays alongside the gateway
 * is the money that is handed over in person — COD, and cash at a POS counter.
 *
 * **The Midtrans rows are seeded active only when there is a key for them.**
 * Both halves of that matter. An active method whose gateway refuses every
 * charge is a checkout that fails at the last step; but now that the manual
 * transfer rows are gone, seeding them inactive unconditionally would leave a
 * fresh install with **no way at all** to pay for a pickup order, since COD is
 * fenced to deliveries. So the switch follows `MIDTRANS_SERVER_KEY`, and an
 * install without one fails loudly at the pay button rather than quietly at
 * the checkout screen. A row that already exists keeps whatever the shop set.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->methods() as $method) {
            $existing = PaymentMethod::query()->where('code', $method['code'])->first();

            if ($existing instanceof PaymentMethod) {
                $existing->update($this->refreshable($method));

                continue;
            }

            PaymentMethod::query()->create($method);
        }
    }

    /**
     * The columns re-seeding may overwrite on a row that already exists.
     *
     * @param  array<string, mixed>  $method
     * @return array<string, mixed>
     */
    private function refreshable(array $method): array
    {
        unset($method['is_active'], $method['sort_order'], $method['config']);

        return $method;
    }

    /**
     * Whether this install has a Midtrans server key to charge against.
     *
     * Read from config rather than from `env()` directly so that a cached
     * config and a test that overrides the value both behave the same way.
     */
    private function midtransIsConfigured(): bool
    {
        $key = config('services.midtrans.server_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function methods(): array
    {
        return [
            [
                'code' => 'cod',
                'name' => 'Bayar di Tempat (COD)',
                'type' => PaymentMethodType::Cod,
                'driver' => PaymentDriver::Manual,
                'provider_code' => null,
                'instructions' => "Siapkan uang tunai sesuai total pesanan. Kurir menerima pembayaran saat barang diantar.\n\n"
                    .'Pesanan COD langsung diproses cabang, jadi pembatalannya harus lewat cabang.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                // A ceiling, because COD risk is the courier carrying cash: an
                // order above this is asked to transfer instead (P2-E1-T7).
                'max_amount' => 2_000_000,
                'is_active' => true,
                'sort_order' => 30,
                'config' => ['fulfillment_types' => ['delivery']],
            ],
            [
                'code' => 'cash',
                'name' => 'Tunai di Kasir',
                'type' => PaymentMethodType::Cash,
                'driver' => PaymentDriver::Manual,
                'provider_code' => null,
                'instructions' => 'Pembayaran tunai di kasir cabang.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                // Never offered online; it exists so POS (Phase 3) records its
                // takings against the same two tables.
                'is_active' => true,
                'sort_order' => 90,
                'config' => null,
            ],
            // `midtrans-va` is the BCA row and keeps the code it was first
            // seeded under, so an install that already has it is refreshed
            // rather than given a second BCA method beside the old one.
            $this->virtualAccount('midtrans-va', 'bca', 'BCA', 40),
            $this->virtualAccount('midtrans-va-bni', 'bni', 'BNI', 41),
            $this->virtualAccount('midtrans-va-bri', 'bri', 'BRI', 42),
            $this->virtualAccount('midtrans-va-permata', 'permata', 'Permata', 43),
            [
                'code' => 'midtrans-qris',
                'name' => 'QRIS',
                'type' => PaymentMethodType::Qris,
                'driver' => PaymentDriver::Midtrans,
                'provider_code' => 'qris',
                'instructions' => 'Pindai kode QR di bawah dengan aplikasi bank atau e-wallet apa pun. '
                    .'Status pesanan diperbarui otomatis setelah pembayaran berhasil.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => 10_000_000,
                'is_active' => $this->midtransIsConfigured(),
                'sort_order' => 50,
                'config' => null,
            ],
            [
                'code' => 'midtrans-ewallet',
                'name' => 'GoPay',
                'type' => PaymentMethodType::EWallet,
                'driver' => PaymentDriver::Midtrans,
                'provider_code' => 'gopay',
                'instructions' => 'Buka aplikasi Gojek/GoPay lewat tombol di bawah, atau pindai kode QR-nya dari perangkat lain. '
                    .'Status pesanan diperbarui otomatis setelah pembayaran berhasil.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'is_active' => $this->midtransIsConfigured(),
                'sort_order' => 60,
                'config' => null,
            ],
        ];
    }

    /**
     * A virtual account at one bank, charged through Midtrans.
     *
     * @return array<string, mixed>
     */
    private function virtualAccount(string $code, string $bank, string $bankName, int $sortOrder): array
    {
        return [
            'code' => $code,
            'name' => "Virtual Account {$bankName}",
            'type' => PaymentMethodType::VirtualAccount,
            'driver' => PaymentDriver::Midtrans,
            'provider_code' => $bank,
            'instructions' => "Transfer ke nomor Virtual Account {$bankName} di bawah lewat m-banking, internet banking, atau ATM. "
                .'Status pesanan diperbarui otomatis setelah pembayaran berhasil.',
            'fee_flat' => 0,
            'fee_percent' => 0,
            'min_amount' => null,
            'max_amount' => null,
            'is_active' => $this->midtransIsConfigured(),
            'sort_order' => $sortOrder,
            'config' => null,
        ];
    }
}
