<?php

/**
 * Turn one cart into an order, the way the checkout endpoint does.
 *
 * Usage: place-order.php <startAt> <cartId> <branchId> <customerId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;

[$startAt, $cartId, $branchId, $customerId] = [
    (float) $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
];

$cart = Cart::findOrFail($cartId);
$branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
$customer = User::findOrFail($customerId);

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => [
    'order_number' => app(PlaceOrder::class)->handle($cart, $branch, $customer, [
        'fulfillment_type' => FulfillmentType::Pickup,
        'address_id' => null,
        'customer_note' => null,
    ])->order_number,
]);
