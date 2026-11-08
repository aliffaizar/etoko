<?php

/**
 * Open a payment attempt for one order, the way the storefront's pay button does.
 *
 * Usage: initiate-payment.php <startAt> <orderId> <methodId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Payment\InitiatePayment;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Scopes\BranchScope;

[$startAt, $orderId, $methodId] = [(float) $argv[1], (int) $argv[2], (int) $argv[3]];

$order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($orderId);
$method = PaymentMethod::findOrFail($methodId);

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => ['reference' => app(InitiatePayment::class)->handle($order, $method)->reference]);
