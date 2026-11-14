<?php

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\VoucherChannel;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Pricing\VoucherLine;
use App\Services\Pricing\VoucherOutcome;
use App\Services\Pricing\VoucherValidator;

/*
 * The voucher rules (P2-E5-T3, ADR-064).
 *
 * One validator answers the cart screen, the checkout screen and
 * `PlaceOrder`, so every refusal and every figure is pinned here once.
 */

/**
 * @param  array<int, VoucherLine>  $lines
 */
function checkVoucher(
    Voucher $voucher,
    array $lines,
    ?User $customer = null,
    ?Branch $branch = null,
    OrderChannel $channel = OrderChannel::Online,
    ?FulfillmentType $fulfillment = null,
    ?int $shippingCost = null,
): VoucherOutcome {
    return app(VoucherValidator::class)->check(
        $voucher,
        $lines,
        $branch,
        $customer ?? User::factory()->customer()->create(),
        $channel,
        $fulfillment,
        $shippingCost,
    );
}

function voucherLine(int $subtotal, int $productId = 1, int $categoryId = 1): VoucherLine
{
    return new VoucherLine($productId, $categoryId, $subtotal);
}

describe('refusals', function () {
    it('refuses a voucher that is switched off', function () {
        $voucher = Voucher::factory()->inactive()->create(['code' => 'MATI']);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toBe('Voucher MATI sedang tidak aktif.');
    });

    it('refuses a voucher that has not started yet', function () {
        $voucher = Voucher::factory()->create([
            'code' => 'NANTI',
            'starts_at' => '2026-12-01 09:00:00',
        ]);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toBe('Voucher NANTI baru berlaku mulai 1 Desember 2026, 09:00.');
    });

    it('refuses a voucher whose period has ended', function () {
        $voucher = Voucher::factory()->ended()->create(['code' => 'LEWAT']);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toStartWith('Voucher LEWAT sudah berakhir pada ');
    });

    it('refuses a counter-only voucher online', function () {
        $voucher = Voucher::factory()->channel(VoucherChannel::Pos)->create(['code' => 'KASIR']);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toBe('Voucher KASIR hanya berlaku untuk belanja langsung di kasir toko.');
    });

    it('refuses an online-only voucher at the counter', function () {
        $voucher = Voucher::factory()->channel(VoucherChannel::Online)->create(['code' => 'ONLINE']);

        expect(checkVoucher($voucher, [voucherLine(100_000)], channel: OrderChannel::Pos)->rejection)
            ->toBe('Voucher ONLINE hanya berlaku untuk belanja online.');
    });

    it('refuses a branch the voucher was not made for', function () {
        $allowed = Branch::factory()->create();
        $other = Branch::factory()->create(['name' => 'Cabang Bandung']);
        $voucher = Voucher::factory()->atBranches($allowed)->create(['code' => 'JKT']);

        expect(checkVoucher($voucher, [voucherLine(100_000)], branch: $other)->rejection)
            ->toBe('Voucher JKT tidak berlaku di cabang Cabang Bandung.')
            ->and(checkVoucher($voucher, [voucherLine(100_000)], branch: $allowed)->isAccepted())->toBeTrue();
    });

    it('asks for a branch when the voucher is narrowed to some and none is chosen', function () {
        $voucher = Voucher::factory()->atBranches(Branch::factory()->create())->create(['code' => 'JKT']);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toBe('Pilih cabang belanja dulu: voucher JKT hanya berlaku di cabang tertentu.');
    });

    it('refuses a voucher whose quota is used up', function () {
        $voucher = Voucher::factory()->quota(5, used: 5)->create(['code' => 'HABIS']);

        expect(checkVoucher($voucher, [voucherLine(100_000)])->rejection)
            ->toBe('Kuota voucher HABIS sudah habis.');
    });

    it('asks a guest to sign in', function () {
        $voucher = Voucher::factory()->create();

        $outcome = app(VoucherValidator::class)->check($voucher, [voucherLine(100_000)], null, null);

        expect($outcome->rejection)->toBe('Masuk ke akun Anda untuk memakai voucher.');
    });

    it('refuses a customer who has used up their allowance', function () {
        $customer = User::factory()->customer()->create();
        $voucher = Voucher::factory()->perUser(2)->create(['code' => 'DUAKALI']);
        VoucherUsage::factory()->count(2)->for($voucher)->create(['user_id' => $customer->getKey()]);

        expect(checkVoucher($voucher, [voucherLine(100_000)], $customer)->rejection)
            ->toBe('Anda sudah memakai voucher DUAKALI sebanyak 2 kali, batas untuk setiap pelanggan.');
    });

    it('does not count a released use against the customer', function () {
        $customer = User::factory()->customer()->create();
        $voucher = Voucher::factory()->perUser(1)->create();
        VoucherUsage::factory()->released()->for($voucher)->create(['user_id' => $customer->getKey()]);

        expect(checkVoucher($voucher, [voucherLine(100_000)], $customer)->isAccepted())->toBeTrue();
    });

    it('sets no allowance when the per-customer limit is zero', function () {
        $customer = User::factory()->customer()->create();
        $voucher = Voucher::factory()->perUser(0)->create();
        VoucherUsage::factory()->count(3)->for($voucher)->create(['user_id' => $customer->getKey()]);

        expect(checkVoucher($voucher, [voucherLine(100_000)], $customer)->isAccepted())->toBeTrue();
    });

    it('refuses a basket with nothing the voucher covers', function () {
        $voucher = Voucher::factory()->forProducts(Product::factory()->create())->create(['code' => 'SATU']);

        expect(checkVoucher($voucher, [voucherLine(100_000, productId: 999_999)])->rejection)
            ->toBe('Voucher SATU tidak berlaku untuk produk di keranjang Anda.');
    });

    it('measures the minimum spend against the covered lines and says how much is missing', function () {
        $covered = Product::factory()->create();
        $voucher = Voucher::factory()->forProducts($covered)->minimum(150_000)->create(['code' => 'MIN150']);

        $outcome = checkVoucher($voucher, [
            voucherLine(100_000, productId: $covered->getKey()),
            voucherLine(500_000, productId: 999_999),
        ]);

        expect($outcome->rejection)->toBe(
            'Voucher MIN150 butuh belanja minimal Rp 150.000 untuk produk yang termasuk voucher ini. Tambah Rp 50.000 lagi untuk memakainya.',
        );
    });

    it('refuses free shipping on a pickup', function () {
        $voucher = Voucher::factory()->freeShipping()->create(['code' => 'ONGKIR']);

        expect(checkVoucher($voucher, [voucherLine(100_000)], fulfillment: FulfillmentType::Pickup)->rejection)
            ->toBe('Voucher ONGKIR gratis ongkir hanya berlaku untuk pesanan yang dikirim, bukan diambil di toko.');
    });
});

describe('the discount', function () {
    it('takes a percentage off the covered goods, rounded down', function () {
        $voucher = Voucher::factory()->percentage(15)->create();

        expect(checkVoucher($voucher, [voucherLine(99_999)])->goodsDiscount)->toBe(14_999);
    });

    it('caps a percentage at the maximum discount', function () {
        $voucher = Voucher::factory()->percentage(50, maxDiscount: 30_000)->create();

        expect(checkVoucher($voucher, [voucherLine(200_000)])->goodsDiscount)->toBe(30_000);
    });

    it('never takes a fixed amount beyond the covered goods', function () {
        $voucher = Voucher::factory()->fixed(80_000)->create();

        expect(checkVoucher($voucher, [voucherLine(50_000)])->goodsDiscount)->toBe(50_000)
            ->and(checkVoucher($voucher, [voucherLine(200_000)])->goodsDiscount)->toBe(80_000);
    });

    it('covers a category together with its subcategories', function () {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $voucher = Voucher::factory()->percentage(10)->forCategories($root)->create();

        $outcome = checkVoucher($voucher, [
            'covered' => voucherLine(100_000, categoryId: $child->getKey()),
            'outside' => voucherLine(300_000, categoryId: Category::factory()->create()->getKey()),
        ]);

        expect($outcome->eligibleSubtotal)->toBe(100_000)
            ->and($outcome->goodsDiscount)->toBe(10_000)
            ->and($outcome->lineDiscounts)->toBe(['covered' => 10_000]);
    });

    it('leaves the free-shipping discount open until the delivery charge is known', function () {
        $voucher = Voucher::factory()->freeShipping(maxDiscount: 20_000)->create();

        expect(checkVoucher($voucher, [voucherLine(100_000)])->shippingDiscount)->toBeNull()
            ->and(checkVoucher($voucher, [voucherLine(100_000)], fulfillment: FulfillmentType::Delivery, shippingCost: 15_000)->shippingDiscount)->toBe(15_000)
            ->and(checkVoucher($voucher, [voucherLine(100_000)], fulfillment: FulfillmentType::Delivery, shippingCost: 35_000)->shippingDiscount)->toBe(20_000);
    });
});

describe('sharing the discount across lines', function () {
    it('splits in proportion and adds up to the discount exactly', function () {
        $shares = VoucherValidator::allocate(10_000, [
            voucherLine(10_000),
            voucherLine(10_000),
            voucherLine(10_000),
        ]);

        expect(array_sum($shares))->toBe(10_000)
            ->and($shares)->toBe([3_334, 3_333, 3_333]);
    });

    it('never discounts a line beyond its own subtotal', function () {
        $shares = VoucherValidator::allocate(1_001, [voucherLine(1), voucherLine(1_000)]);

        expect($shares)->toBe([1, 1_000]);
    });
});
