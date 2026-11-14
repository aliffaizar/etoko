<?php

namespace Database\Seeders;

use App\Actions\Voucher\SaveVoucher;
use App\Enums\VoucherAppliesTo;
use App\Enums\VoucherChannel;
use App\Enums\VoucherType;
use App\Models\Category;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * Four vouchers for local development (P2-E5), one of each shape worth
 * trying at the storefront: a capped percentage, a fixed amount with a
 * minimum spend, free shipping, and one narrowed to a category.
 *
 * Saved through `SaveVoucher`, the action the admin form uses. Skipped per
 * code, so re-running never duplicates or overwrites an edited voucher.
 * Never run it against a production database.
 */
class VoucherDemoSeeder extends Seeder
{
    public function run(SaveVoucher $saveVoucher): void
    {
        $electronics = Category::query()->where('slug', 'elektronik')->value('id');

        $vouchers = [
            [
                'code' => 'HEMAT10',
                'name' => 'Hemat 10%',
                'type' => VoucherType::Percentage,
                'value' => 10,
                'max_discount' => 50_000,
                'quota' => 100,
                'per_user_limit' => 1,
                'categories' => [],
            ],
            [
                'code' => 'POTONG25K',
                'name' => 'Potongan Rp25.000',
                'type' => VoucherType::Fixed,
                'value' => 25_000,
                'min_order_amount' => 200_000,
                'per_user_limit' => 3,
                'categories' => [],
            ],
            [
                'code' => 'GRATISONGKIR',
                'name' => 'Gratis ongkir',
                'type' => VoucherType::FreeShipping,
                'max_discount' => 20_000,
                'min_order_amount' => 100_000,
                'per_user_limit' => 0,
                'categories' => [],
            ],
            [
                'code' => 'ELEKTRONIK5',
                'name' => 'Elektronik 5%',
                'type' => VoucherType::Percentage,
                'value' => 5,
                'per_user_limit' => 2,
                'categories' => $electronics === null ? [] : [(int) $electronics],
            ],
        ];

        foreach ($vouchers as $voucher) {
            if (Voucher::query()->where('code', $voucher['code'])->exists()) {
                continue;
            }

            $categories = $voucher['categories'];
            unset($voucher['categories']);

            $saveVoucher->handle([
                'attributes' => [
                    'value' => 0,
                    'max_discount' => null,
                    'min_order_amount' => 0,
                    'quota' => null,
                    'channel' => VoucherChannel::All,
                    'is_active' => true,
                    'applies_to' => $categories === [] ? VoucherAppliesTo::All : VoucherAppliesTo::Category,
                    'ends_at' => now()->addMonths(3),
                    ...$voucher,
                ],
                'branch_ids' => [],
                'category_ids' => $categories,
                'product_ids' => [],
            ]);
        }
    }
}
