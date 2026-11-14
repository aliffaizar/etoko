<?php

/**
 * One customer checks out a one-line cart with a voucher applied.
 *
 * Each worker makes its own customer and cart **before** the barrier, so the
 * processes race for the voucher's quota and nothing else — a customer has
 * one cart, and that cart's own lock is a different test (HIGH-01).
 *
 * Usage: place-order-with-voucher.php <startAt> <voucherId> <branchId> <variantId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Order\PlaceOrder;
use App\Enums\FulfillmentType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;

[$startAt, $voucherId, $branchId, $variantId] = [
    (float) $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
];

$branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
$variant = ProductVariant::withoutGlobalScopes()->findOrFail($variantId);
$customer = User::factory()->customer()->create();

$cart = Cart::factory()->forUser($customer)->create([
    'branch_id' => $branch->getKey(),
    'voucher_id' => $voucherId,
]);
CartItem::factory()->forCart($cart)->forVariant($variant)->create([
    'quantity' => 1,
    'unit_price_snapshot' => $variant->effectivePrice(),
]);

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => [
    'order_number' => app(PlaceOrder::class)->handle($cart, $branch, $customer, [
        'fulfillment_type' => FulfillmentType::Pickup,
        'address_id' => null,
        'customer_note' => null,
    ])->order_number,
]);
