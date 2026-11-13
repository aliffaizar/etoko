<?php

/**
 * Ship every line of an approved stock transfer at its approved figure (P2-E3-T2).
 *
 * Usage: ship-stock-transfer.php <startAt> <transferId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Inventory\ShipStockTransfer;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;

[$startAt, $transferId] = [(float) $argv[1], (int) $argv[2]];

$transfer = StockTransfer::acrossBranches()->with('items')->findOrFail($transferId);
$lines = $transfer->items
    ->map(fn (StockTransferItem $item): array => ['id' => $item->id, 'quantity' => (int) $item->quantity_approved])
    ->all();

awaitStartSignal($startAt);

reportOutcomeOf(function () use ($transfer, $lines): array {
    app(ShipStockTransfer::class)->handle($transfer, $lines);

    return ['transfer_id' => $transfer->getKey()];
});
