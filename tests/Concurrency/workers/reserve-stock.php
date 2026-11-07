<?php

/**
 * Hold `quantity` units of a variant for a new order at a branch (BR-6).
 *
 * Usage: reserve-stock.php <startAt> <branchId> <variantId> <quantity>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Order\ReserveStock;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Scopes\BranchScope;

[$startAt, $branchId, $variantId, $quantity] = [
    (float) $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
];

$branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
$variant = ProductVariant::withoutGlobalScopes()->findOrFail($variantId);

$order = Order::withoutGlobalScope(BranchScope::class)->create([
    'order_number' => 'CONC-'.getmypid().'-'.random_int(1000, 9999),
    'branch_id' => $branch->getKey(),
    'channel' => OrderChannel::Online,
    'fulfillment_type' => FulfillmentType::Pickup,
    'status' => OrderStatus::PendingPayment,
    'payment_status' => PaymentStatus::Unpaid,
    'customer_name' => 'Uji Konkurensi',
    'subtotal' => 0,
    'discount_total' => 0,
    'shipping_cost' => 0,
    'tax_total' => 0,
    'grand_total' => 0,
    'total_weight_gram' => 0,
    'placed_at' => now(),
]);

awaitStartSignal($startAt);

reportOutcomeOf(function () use ($order, $variant, $quantity): array {
    app(ReserveStock::class)->handle($order, [['variant' => $variant, 'quantity' => $quantity]]);

    return ['order_id' => $order->getKey()];
});
