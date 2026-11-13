<?php

/**
 * Ask for an order's invoice (P2-E6-T1, ADR-059).
 *
 * Usage: generate-invoice.php <startAt> <orderId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Order\GenerateInvoice;
use App\Models\Order;

[$startAt, $orderId] = [(float) $argv[1], (int) $argv[2]];

$order = Order::withoutGlobalScopes()->findOrFail($orderId);

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => [
    'invoice' => app(GenerateInvoice::class)->handle($order)->invoice_number,
]);
