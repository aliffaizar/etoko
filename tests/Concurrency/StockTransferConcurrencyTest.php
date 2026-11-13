<?php

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Tests\Concurrency\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

/*
 * Two people pressing "Kirim" on the same transfer (P2-E3-T2, BR-6).
 *
 * `ShipStockTransfer` locks the transfer row before it reads the status, so
 * the second click finds it already in transit and is refused — the origin's
 * stock leaves once, not twice.
 */

it('ships a transfer exactly once when three staff press ship together', function () {
    $from = Warehouse::factory()->default()->for(Branch::factory()->default())->create();
    $to = Warehouse::factory()->default()->for(Branch::factory())->create();
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();
    Stock::factory()->inWarehouse($from)->forVariant($variant)->quantity(100)->create();

    $transfer = StockTransfer::factory()->between($from, $to)->approved()->create();
    StockTransferItem::factory()->for($transfer)->forVariant($variant)->requested(10)->approved()->create();

    $results = $this->runWorkers('ship-stock-transfer.php', [$transfer->getKey()], copies: 3);

    expect($this->countSucceeded($results))->toBe(1)
        ->and($this->refusalsIn($results))->each->toBe(ValidationException::class)
        ->and(Stock::acrossBranches()->where('warehouse_id', $from->id)->value('quantity'))->toBe(90)
        ->and(StockMovement::acrossBranches()->where('type', StockMovementType::TransferOut)->count())->toBe(1)
        ->and($transfer->refresh()->status)->toBe(StockTransferStatus::InTransit);
});
