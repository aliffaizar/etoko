<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an e-wallet app sends the customer back to (P2-E1-T5, ADR-050).
 *
 * GoPay and ShopeePay are paid in another app, and `MidtransGateway` gives
 * each charge this URL as its `callback_url` so the customer is returned to
 * the shop afterwards instead of being left in the wallet.
 *
 * **Nothing here is trusted and nothing here is written.** The query string
 * is whatever the customer's browser was handed, and a customer can edit a
 * URL: treating a `result=success` in it as fact would be a button for
 * marking your own order paid. So this reads one thing — which order to show
 * — and the order page then asks Midtrans itself what became of the payment
 * (`ReconcilePayment`, ADR-053).
 *
 * `order_id` is the payment's `reference`, not the order number: that is what
 * the driver keys the transaction on, per attempt (see `MidtransGateway`).
 *
 * **A customer coming back from paying is never shown a 404.** An unknown
 * reference, a reference belonging to somebody else, and a missing one all end
 * at the same place — the customer's own order list — because the alternative
 * is a dead end on the one screen where somebody has just parted with money.
 * They are treated *identically* on purpose: a 404 for one and a redirect for
 * another would say whether a reference exists.
 *
 * There is no flash message. Whether the money arrived is something Midtrans
 * knows and this URL does not; a toast written here would be a guess printed
 * next to the truth.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        $order = $this->ownOrderFor($request->query('order_id'), $customer);

        return $order instanceof Order
            ? redirect()->route('shop.account.orders.show', $order)
            : redirect()->route('shop.account.orders.index');
    }

    /**
     * The order behind that payment reference, when it is this customer's.
     *
     * Returns null for anything else — unreadable, unknown, or somebody
     * else's — so the caller has one case to handle rather than three.
     */
    private function ownOrderFor(mixed $reference, User $customer): ?Order
    {
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $order = Payment::query()
            ->where('reference', $reference)
            ->first()
            ?->order;

        if (! $order instanceof Order || $order->user_id !== $customer->getKey()) {
            return null;
        }

        return $order;
    }
}
