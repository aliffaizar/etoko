<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ApplyVoucherRequest;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Branch\StorefrontBranch;
use App\Services\Cart\CartService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Applying and removing a voucher on the cart (P2-E5-T3).
 *
 * Signed-in customers only: every voucher counts against a per-customer
 * allowance, and checkout needs an account anyway. A code is checked against
 * the basket as it is now and refused on the field with the validator's
 * sentence; an accepted one is remembered on the cart (`carts.voucher_id`)
 * and checked again on every screen after, and finally by `PlaceOrder`.
 *
 * Applying promises nothing: no quota is taken until the order is written
 * (`RedeemVoucher`).
 */
class CartVoucherController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly StorefrontBranch $storefrontBranch,
    ) {}

    public function store(ApplyVoucherRequest $request): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $code = (string) $request->validated('voucher_code');
        $voucher = Voucher::findByCode($code);

        if (! $voucher instanceof Voucher) {
            return back()->withErrors([
                'voucher_code' => 'Kode voucher '.Voucher::normalizeCode($code).' tidak ditemukan.',
            ]);
        }

        $cart = $this->cartService->resolve($customer);

        if ($cart->items()->doesntExist()) {
            return back()->withErrors(['voucher_code' => 'Keranjang Anda masih kosong.']);
        }

        $cart->voucher_id = $voucher->getKey();
        $cart->setRelation('voucher', $voucher);
        $cart->load('items.variant.product');

        $outcome = $this->cartService->voucherOutcome($cart, $this->storefrontBranch->current(), $customer);

        if ($outcome === null || ! $outcome->isAccepted()) {
            return back()->withErrors(['voucher_code' => (string) $outcome?->rejection]);
        }

        $cart->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Voucher {$voucher->code} dipakai: {$voucher->benefitLabel()}."]);

        return back();
    }

    public function destroy(): RedirectResponse
    {
        /** @var User $customer */
        $customer = auth()->user();
        $cart = $this->cartService->resolve($customer);

        $cart->forceFill(['voucher_id' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Voucher dilepas dari keranjang.']);

        return back();
    }
}
