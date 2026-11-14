<?php

use App\Actions\Order\PlaceOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Voucher\RedeemVoucher;
use App\Actions\Voucher\ReleaseVoucher;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Exceptions\CheckoutFailedException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Warehouse;

/*
 * A voucher on a real order (P2-E5-T3, T4, ADR-064): the totals `PlaceOrder`
 * writes, the redemption it records under the voucher's lock, and the
 * release when the order never goes ahead.
 *
 * Concurrent redemption of the last unit of quota is in
 * tests/Concurrency/VoucherConcurrencyTest.php — a row lock only blocks
 * another connection.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create(['supports_delivery' => true, 'supports_pickup' => true]);
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
    $this->address = Address::factory()->for($this->customer)->create(['is_default' => true]);
    config(['shop.flat_shipping_cost' => 15_000, 'shop.tax_percent' => 0]);
});

/**
 * A stocked variant at the test branch, priced at `$price`.
 */
function voucherVariant(int $price): ProductVariant
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create(['base_price' => $price])->variants()->sole();

    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(20)->create();

    return $variant;
}

/**
 * The customer's cart holding `$lines` (variant, quantity) with `$voucher` applied.
 *
 * @param  array<int, array{0: ProductVariant, 1: int}>  $lines
 */
function voucherCart(array $lines, ?Voucher $voucher): Cart
{
    $cart = Cart::factory()->forUser(test()->customer)->create([
        'branch_id' => test()->branch->getKey(),
        'voucher_id' => $voucher?->getKey(),
    ]);

    foreach ($lines as [$variant, $quantity]) {
        CartItem::factory()->forCart($cart)->forVariant($variant)->create([
            'quantity' => $quantity,
            'unit_price_snapshot' => $variant->effectivePrice(),
        ]);
    }

    return $cart;
}

function placeVoucherOrder(Cart $cart, FulfillmentType $fulfillment = FulfillmentType::Delivery): Order
{
    return app(PlaceOrder::class)->handle($cart, test()->branch, test()->customer, [
        'fulfillment_type' => $fulfillment,
        'address_id' => $fulfillment === FulfillmentType::Delivery ? test()->address->getKey() : null,
        'customer_note' => null,
    ]);
}

describe('the order it writes', function () {
    it('takes the goods discount off the total and shares it across the lines', function () {
        $voucher = Voucher::factory()->percentage(10)->create(['code' => 'HEMAT10']);
        $cart = voucherCart([[voucherVariant(100_000), 2], [voucherVariant(50_000), 1]], $voucher);

        $order = placeVoucherOrder($cart);
        $items = $order->items()->orderBy('id')->get();

        expect($order->subtotal)->toBe(250_000)
            ->and($order->discount_total)->toBe(25_000)
            ->and($order->shipping_cost)->toBe(15_000)
            ->and($order->grand_total)->toBe(240_000)
            ->and($order->voucher_id)->toBe($voucher->getKey())
            ->and($order->voucher_code)->toBe('HEMAT10')
            ->and($items->pluck('discount_amount')->all())->toBe([20_000, 5_000])
            ->and($items->pluck('subtotal')->all())->toBe([180_000, 45_000]);
    });

    it('charges tax on the goods after the discount', function () {
        config(['shop.tax_percent' => 10]);
        $voucher = Voucher::factory()->fixed(20_000)->create();

        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], $voucher));

        expect($order->tax_total)->toBe(8_000)
            ->and($order->grand_total)->toBe(100_000 - 20_000 + 15_000 + 8_000);
    });

    it('takes free shipping off the delivery charge up to its ceiling, leaving the lines alone', function () {
        $voucher = Voucher::factory()->freeShipping(maxDiscount: 10_000)->create();

        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], $voucher));

        expect($order->shipping_cost)->toBe(15_000)
            ->and($order->discount_total)->toBe(10_000)
            ->and($order->grand_total)->toBe(105_000)
            ->and($order->items()->sole()->discount_amount)->toBe(0);
    });

    it('records one redemption and takes the voucher off the cart', function () {
        $voucher = Voucher::factory()->fixed(10_000)->quota(5, used: 2)->create();
        $cart = voucherCart([[voucherVariant(100_000), 1]], $voucher);

        $order = placeVoucherOrder($cart);
        $usage = VoucherUsage::query()->sole();

        expect($usage->order_id)->toBe($order->getKey())
            ->and($usage->user_id)->toBe($this->customer->getKey())
            ->and($usage->discount_amount)->toBe(10_000)
            ->and($usage->released_at)->toBeNull()
            ->and($voucher->fresh()->used_count)->toBe(3)
            ->and($cart->fresh()->voucher_id)->toBeNull();
    });

    it('places an order without a voucher exactly as before', function () {
        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], null));

        expect($order->discount_total)->toBe(0)
            ->and($order->voucher_id)->toBeNull()
            ->and(VoucherUsage::query()->count())->toBe(0);
    });
});

describe('refusals', function () {
    it('refuses the order, writing nothing, when the voucher no longer applies', function () {
        $voucher = Voucher::factory()->minimum(500_000)->create(['code' => 'BESAR']);
        $cart = voucherCart([[voucherVariant(100_000), 1]], $voucher);

        expect(fn () => placeVoucherOrder($cart))->toThrow(function (CheckoutFailedException $refused) {
            expect($refused->concernsVoucher)->toBeTrue()
                ->and($refused->getMessage())->toStartWith('Voucher BESAR butuh belanja minimal');
        });

        expect(Order::acrossBranches()->count())->toBe(0)
            ->and(StockReservation::acrossBranches()->count())->toBe(0)
            ->and($cart->items()->count())->toBe(1);
    });

    it('refuses free shipping on a pickup', function () {
        $voucher = Voucher::factory()->freeShipping()->create();
        $cart = voucherCart([[voucherVariant(100_000), 1]], $voucher);

        expect(fn () => placeVoucherOrder($cart, FulfillmentType::Pickup))
            ->toThrow(CheckoutFailedException::class, 'gratis ongkir hanya berlaku untuk pesanan yang dikirim');
    });

    it('refuses under the lock when the quota went while the totals were being worked out', function () {
        $voucher = Voucher::factory()->quota(1)->create(['code' => 'TERAKHIR']);
        $order = Order::factory()->forBranch($this->branch)->create();
        $voucher->update(['used_count' => 1]);

        DB::beginTransaction();

        try {
            expect(fn () => app(RedeemVoucher::class)->handle($voucher, $order, $this->customer, 10_000))
                ->toThrow(CheckoutFailedException::class, 'Kuota voucher TERAKHIR baru saja habis dipakai pembeli lain.');
        } finally {
            DB::rollBack();
        }
    });

    it('refuses under the lock a customer whose allowance went in another tab', function () {
        $voucher = Voucher::factory()->perUser(1)->create(['code' => 'SEKALI']);
        VoucherUsage::factory()->for($voucher)->create(['user_id' => $this->customer->getKey()]);
        $order = Order::factory()->forBranch($this->branch)->create();

        DB::beginTransaction();

        try {
            expect(fn () => app(RedeemVoucher::class)->handle($voucher, $order, $this->customer, 10_000))
                ->toThrow(CheckoutFailedException::class, 'Anda sudah memakai voucher SEKALI sebanyak batas yang diizinkan.');
        } finally {
            DB::rollBack();
        }
    });
});

describe('giving it back', function () {
    it('releases the voucher when the order is cancelled or expires', function (OrderStatus $closed) {
        $voucher = Voucher::factory()->fixed(10_000)->quota(3)->create();
        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], $voucher));

        app(TransitionOrderStatus::class)->handle($order, $closed);

        expect($voucher->fresh()->used_count)->toBe(0)
            ->and(VoucherUsage::query()->sole()->released_at)->not->toBeNull()
            ->and($order->fresh()->discount_total)->toBe(10_000);
    })->with([
        'cancelled' => [OrderStatus::Cancelled],
        'expired' => [OrderStatus::Expired],
    ]);

    it('keeps the voucher used when the order is paid', function () {
        $voucher = Voucher::factory()->fixed(10_000)->create();
        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], $voucher));

        app(TransitionOrderStatus::class)->handle($order, OrderStatus::Paid);

        expect($voucher->fresh()->used_count)->toBe(1)
            ->and(VoucherUsage::query()->live()->count())->toBe(1);
    });

    it('gives back one unit however often it is asked', function () {
        $voucher = Voucher::factory()->fixed(10_000)->create();
        $order = placeVoucherOrder(voucherCart([[voucherVariant(100_000), 1]], $voucher));

        expect(app(ReleaseVoucher::class)->handle($order))->toBeTrue()
            ->and(app(ReleaseVoucher::class)->handle($order))->toBeFalse()
            ->and($voucher->fresh()->used_count)->toBe(0);
    });
});
