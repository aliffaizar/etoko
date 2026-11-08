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
 * which methods it offers, in what order, and into which bank account — and
 * re-seeding must not switch a disabled method back on or overwrite a real
 * account number with the placeholder below.
 *
 * The bank and e-wallet accounts here are **placeholders** and say so in the
 * instructions, because a made-up account number that reads as real is one a
 * customer will eventually transfer money to.
 *
 * The Midtrans rows are seeded **inactive**: their driver needs a server key
 * the project does not have yet (`P2-E1-T5`), and an active method whose
 * gateway refuses every charge is a checkout that fails at the last step.
 * Switching them on is a matter of filling in `MIDTRANS_SERVER_KEY` and
 * flipping `is_active`.
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
     * @return array<int, array<string, mixed>>
     */
    private function methods(): array
    {
        return [
            [
                'code' => 'bank-transfer-bca',
                'name' => 'Transfer Bank BCA',
                'type' => PaymentMethodType::BankTransfer,
                'driver' => PaymentDriver::Manual,
                'provider_code' => 'bca',
                'instructions' => "Transfer **tepat sesuai nominal** ke rekening di atas, lalu unggah bukti transfernya di halaman pesanan ini.\n\n"
                    ."Nominal yang berbeda — termasuk selisih seribu rupiah — membuat staf tidak bisa mencocokkan pembayaran Anda.\n\n"
                    .'Nomor rekening di atas masih contoh; ganti di pengaturan pembayaran sebelum toko dibuka.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'is_active' => true,
                'sort_order' => 10,
                'config' => [
                    'bank' => [
                        'name' => 'BCA',
                        'account_number' => '0000000000',
                        'account_holder' => 'PT Etoko Nusantara (contoh)',
                    ],
                ],
            ],
            [
                'code' => 'bank-transfer-mandiri',
                'name' => 'Transfer Bank Mandiri',
                'type' => PaymentMethodType::BankTransfer,
                'driver' => PaymentDriver::Manual,
                'provider_code' => 'mandiri',
                'instructions' => "Transfer **tepat sesuai nominal** ke rekening di atas, lalu unggah bukti transfernya di halaman pesanan ini.\n\n"
                    .'Nomor rekening di atas masih contoh; ganti di pengaturan pembayaran sebelum toko dibuka.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'is_active' => true,
                'sort_order' => 20,
                'config' => [
                    'bank' => [
                        'name' => 'Mandiri',
                        'account_number' => '0000000000',
                        'account_holder' => 'PT Etoko Nusantara (contoh)',
                    ],
                ],
            ],
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
            [
                'code' => 'midtrans-va',
                'name' => 'Virtual Account (Midtrans)',
                'type' => PaymentMethodType::VirtualAccount,
                'driver' => PaymentDriver::Midtrans,
                'provider_code' => 'bca',
                'instructions' => 'Bayar lewat Virtual Account yang muncul setelah Anda menekan tombol bayar.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'is_active' => false,
                'sort_order' => 40,
                'config' => ['midtrans' => ['enabled_payments' => ['bca_va', 'bni_va', 'bri_va', 'permata_va']]],
            ],
            [
                'code' => 'midtrans-qris',
                'name' => 'QRIS (Midtrans)',
                'type' => PaymentMethodType::Qris,
                'driver' => PaymentDriver::Midtrans,
                'provider_code' => 'qris',
                'instructions' => 'Pindai QRIS dengan aplikasi bank atau e-wallet apa pun.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => 10_000_000,
                'is_active' => false,
                'sort_order' => 50,
                'config' => ['midtrans' => ['enabled_payments' => ['other_qris']]],
            ],
            [
                'code' => 'midtrans-ewallet',
                'name' => 'E-Wallet (Midtrans)',
                'type' => PaymentMethodType::EWallet,
                'driver' => PaymentDriver::Midtrans,
                'provider_code' => 'gopay',
                'instructions' => 'Bayar dengan GoPay, ShopeePay, atau e-wallet lain yang tersedia.',
                'fee_flat' => 0,
                'fee_percent' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'is_active' => false,
                'sort_order' => 60,
                'config' => ['midtrans' => ['enabled_payments' => ['gopay', 'shopeepay']]],
            ],
        ];
    }
}
