<?php

use App\Models\Address;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\Stock;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Services\Branch\StorefrontBranch;

/*
 * The voucher on the cart and checkout screens (P2-E5-T3).
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create(['supports_delivery' => true, 'supports_pickup' => true]);
    $this->warehouse = Warehouse::factory()->for($this->branch)->default()->create();
    $this->customer = User::factory()->customer()->create();
});

/**
 * The customer's cart with one Rp100.000 line.
 */
function cartForVoucher(?Voucher $voucher = null): Cart
{
    /** @var ProductVariant $variant */
    $variant = Product::factory()->withDefaultVariant()->create(['base_price' => 100_000])->variants()->sole();
    Stock::factory()->inWarehouse(test()->warehouse)->forVariant($variant)->quantity(10)->create();

    $cart = Cart::factory()->forUser(test()->customer)->create([
        'branch_id' => test()->branch->getKey(),
        'voucher_id' => $voucher?->getKey(),
    ]);
    CartItem::factory()->forCart($cart)->forVariant($variant)->create(['quantity' => 1, 'unit_price_snapshot' => 100_000]);

    return $cart;
}

describe('applying a code', function () {
    it('remembers an accepted code on the cart, whatever its capitalisation', function () {
        $voucher = Voucher::factory()->percentage(10)->create(['code' => 'HEMAT10']);
        $cart = cartForVoucher();

        $this->actingAs($this->customer)
            ->from(route('shop.cart.index'))
            ->post(route('shop.cart.voucher.store'), ['voucher_code' => ' hemat10 '])
            ->assertRedirect(route('shop.cart.index'))
            ->assertSessionHasNoErrors();

        expect($cart->fresh()->voucher_id)->toBe($voucher->getKey());
    });

    it('says so when the code does not exist', function () {
        cartForVoucher();

        $this->actingAs($this->customer)
            ->post(route('shop.cart.voucher.store'), ['voucher_code' => 'ngawur'])
            ->assertSessionHasErrors(['voucher_code' => 'Kode voucher NGAWUR tidak ditemukan.']);
    });

    it('refuses a code that does not apply, with the reason, and remembers nothing', function () {
        Voucher::factory()->minimum(500_000)->create(['code' => 'BESAR']);
        $cart = cartForVoucher();

        $this->actingAs($this->customer)
            ->post(route('shop.cart.voucher.store'), ['voucher_code' => 'BESAR'])
            ->assertSessionHasErrors(['voucher_code' => 'Voucher BESAR butuh belanja minimal Rp 500.000. Tambah Rp 400.000 lagi untuk memakainya.']);

        expect($cart->fresh()->voucher_id)->toBeNull();
    });

    it('requires a code', function () {
        $this->actingAs($this->customer)
            ->post(route('shop.cart.voucher.store'), ['voucher_code' => ''])
            ->assertSessionHasErrors(['voucher_code' => 'kode voucher wajib diisi.']);
    });

    it('turns a guest away to sign in', function () {
        $this->post(route('shop.cart.voucher.store'), ['voucher_code' => 'HEMAT10'])
            ->assertRedirect(route('login'));
    });

    it('takes the voucher off the cart', function () {
        $cart = cartForVoucher(Voucher::factory()->create());

        $this->actingAs($this->customer)
            ->delete(route('shop.cart.voucher.destroy'))
            ->assertRedirect();

        expect($cart->fresh()->voucher_id)->toBeNull();
    });
});

describe('the screens', function () {
    it('shows the cart its voucher and the discount', function () {
        cartForVoucher(Voucher::factory()->percentage(10)->create(['code' => 'HEMAT10']));
        app(StorefrontBranch::class)->choose($this->branch);

        $this->actingAs($this->customer)
            ->get(route('shop.cart.index'))
            ->assertInertia(fn ($page) => $page
                ->where('voucher.code', 'HEMAT10')
                ->where('voucher.is_accepted', true)
                ->where('voucher.goods_discount', 10_000));
    });

    it('tells the cart why a remembered voucher stopped applying', function () {
        cartForVoucher(Voucher::factory()->ended()->create(['code' => 'LEWAT']));

        $this->actingAs($this->customer)
            ->get(route('shop.cart.index'))
            ->assertInertia(fn ($page) => $page
                ->where('voucher.is_accepted', false)
                ->where('voucher.rejection', fn (string $reason) => str_starts_with($reason, 'Voucher LEWAT sudah berakhir')));
    });

    it('takes the voucher off the bill COD is judged against at checkout', function () {
        $address = Address::factory()->for($this->customer)->create(['is_default' => true]);
        ShippingRate::factory()->forService(CourierService::factory()->create())->priced(15_000)->create();
        // COD up to Rp100.000: the bill is Rp115.000 without the voucher and
        // Rp90.000 with it.
        PaymentMethod::factory()->cod()->create(['max_amount' => 100_000]);
        cartForVoucher(Voucher::factory()->fixed(25_000)->create());
        app(StorefrontBranch::class)->choose($this->branch);

        $this->actingAs($this->customer)
            ->get(route('shop.checkout.index', ['alamat' => $address->getKey()]))
            ->assertInertia(fn ($page) => $page
                ->where('voucher.goods_discount', 25_000)
                ->where('payment_methods.delivery.0.is_eligible', true));
    });

    it('puts a checkout refusal beside the voucher and places nothing', function () {
        $address = Address::factory()->for($this->customer)->create(['is_default' => true]);
        $service = CourierService::factory()->create();
        ShippingRate::factory()->forService($service)->priced(15_000)->create();
        cartForVoucher(Voucher::factory()->freeShipping()->create(['code' => 'ONGKIR']));
        app(StorefrontBranch::class)->choose($this->branch);

        $this->actingAs($this->customer)
            ->post(route('shop.checkout.store'), [
                'fulfillment_type' => 'pickup',
                'payment_method_id' => PaymentMethod::factory()->create()->getKey(),
            ])
            ->assertSessionHasErrors(['voucher', 'checkout']);

        expect(Order::acrossBranches()->count())->toBe(0);
    });
});
