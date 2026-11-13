<?php

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\CancelStockTransfer;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RejectStockTransfer;
use App\Actions\Inventory\RequestStockTransfer;
use App\Actions\Inventory\ShipStockTransfer;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/*
 * Stock transfers between branches (P2-E3-T2, T4).
 */

/**
 * Two branches, each with a default warehouse.
 *
 * @return array{0: Warehouse, 1: Warehouse}
 */
function transferEnds(): array
{
    return [
        Warehouse::factory()->default()->for(Branch::factory())->create(),
        Warehouse::factory()->default()->for(Branch::factory())->create(),
    ];
}

function transferVariant(?Warehouse $stockedIn = null, int $quantity = 0, int $reserved = 0): ProductVariant
{
    $variant = Product::factory()->withDefaultVariant()->create()->variants()->sole();

    if ($stockedIn !== null) {
        Stock::factory()->inWarehouse($stockedIn)->forVariant($variant)
            ->quantity($quantity)->reserved($reserved)->create();
    }

    return $variant;
}

function onHand(Warehouse $warehouse, ProductVariant $variant): int
{
    return (int) Stock::acrossBranches()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_variant_id', $variant->id)
        ->value('quantity');
}

/**
 * A transfer at the given step, with one line per variant.
 *
 * @param  array<int, array{0: ProductVariant, 1: int}>  $lines  Variant and quantity, the same at every step reached.
 */
function transferAt(Warehouse $from, Warehouse $to, string $step, array $lines): StockTransfer
{
    $factory = StockTransfer::factory()->between($from, $to);
    $transfer = match ($step) {
        'requested' => $factory->create(),
        'approved' => $factory->approved()->create(),
        'in_transit' => $factory->inTransit()->create(),
    };

    foreach ($lines as [$variant, $quantity]) {
        $item = StockTransferItem::factory()->for($transfer)->forVariant($variant)->requested($quantity);

        match ($step) {
            'requested' => $item->create(),
            'approved' => $item->approved()->create(),
            'in_transit' => $item->shipped()->create(),
        };
    }

    return $transfer->refresh();
}

/**
 * @return array<int, array{id: int, quantity: int}>
 */
function linesOf(StockTransfer $transfer, ?Closure $quantity = null): array
{
    return $transfer->items()->get()
        ->map(fn (StockTransferItem $item): array => [
            'id' => $item->id,
            'quantity' => $quantity === null ? $item->quantity_requested : $quantity($item),
        ])
        ->all();
}

it('records a request without moving any stock', function () {
    [$from, $to] = transferEnds();
    $variant = transferVariant($from, 20);
    $actor = User::factory()->adminCabang($to->branch)->create();

    $transfer = app(RequestStockTransfer::class)->handle($to, $from->branch, [
        'notes' => 'Untuk promo akhir pekan',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 5]],
    ], $actor);

    expect($transfer->status)->toBe(StockTransferStatus::Requested)
        ->and($transfer->code)->toStartWith('TRF-')
        ->and($transfer->from_warehouse_id)->toBe($from->id)
        ->and($transfer->to_branch_id)->toBe($to->branch_id)
        ->and($transfer->requested_by)->toBe($actor->id)
        ->and($transfer->items()->sole()->quantity_requested)->toBe(5)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses to request from the branch the stock would go to', function () {
    [, $to] = transferEnds();

    app(RequestStockTransfer::class)->handle($to, $to->branch, [
        'items' => [['product_variant_id' => transferVariant()->id, 'quantity' => 1]],
    ]);
})->throws(ValidationException::class, 'Cabang asal harus cabang lain');

it('refuses to request from a branch that is not active', function () {
    [$from, $to] = transferEnds();
    $from->branch->update(['status' => 'maintenance']);

    app(RequestStockTransfer::class)->handle($to, $from->branch->refresh(), [
        'items' => [['product_variant_id' => transferVariant()->id, 'quantity' => 1]],
    ]);
})->throws(ValidationException::class, 'Pemeliharaan');

it('records what the origin agreed to send and from which warehouse, still moving nothing', function () {
    [$from, $to] = transferEnds();
    $second = Warehouse::factory()->for($from->branch)->create();
    $variant = transferVariant($from, 20);
    $transfer = transferAt($from, $to, 'requested', [[$variant, 8]]);

    $approved = app(ApproveStockTransfer::class)->handle($transfer, [
        'from_warehouse_id' => $second->id,
        'items' => linesOf($transfer, fn () => 6),
    ]);

    expect($approved->status)->toBe(StockTransferStatus::Approved)
        ->and($approved->from_warehouse_id)->toBe($second->id)
        ->and($approved->items()->sole()->quantity_approved)->toBe(6)
        ->and(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses an approval above what was asked for', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'requested', [[transferVariant(), 3]]);

    app(ApproveStockTransfer::class)->handle($transfer, ['items' => linesOf($transfer, fn () => 4)]);
})->throws(ValidationException::class, 'harus antara 0 dan 3');

it('refuses an approval of nothing at all', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'requested', [[transferVariant(), 3]]);

    app(ApproveStockTransfer::class)->handle($transfer, ['items' => linesOf($transfer, fn () => 0)]);
})->throws(ValidationException::class, 'tolak permintaannya');

it('refuses an approval that leaves a line unanswered', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'requested', [[transferVariant(), 3], [transferVariant(), 2]]);

    app(ApproveStockTransfer::class)->handle($transfer, ['items' => array_slice(linesOf($transfer), 0, 1)]);
})->throws(ValidationException::class, 'belum diisi');

it('refuses a source warehouse that belongs to another branch', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'requested', [[transferVariant(), 3]]);

    app(ApproveStockTransfer::class)->handle($transfer, [
        'from_warehouse_id' => $to->id,
        'items' => linesOf($transfer),
    ]);
})->throws(ValidationException::class, 'milik cabang asal');

it('takes the shipped units out of the origin, and puts them nowhere yet', function () {
    [$from, $to] = transferEnds();
    $variant = transferVariant($from, 20);
    $transfer = transferAt($from, $to, 'approved', [[$variant, 6]]);
    $shipper = User::factory()->adminCabang($from->branch)->create();

    $shipped = app(ShipStockTransfer::class)->handle($transfer, linesOf($transfer, fn () => 5), $shipper);

    $movement = StockMovement::acrossBranches()->sole();

    expect($shipped->status)->toBe(StockTransferStatus::InTransit)
        ->and($shipped->shipped_by)->toBe($shipper->id)
        ->and($shipped->items()->sole()->quantity_shipped)->toBe(5)
        ->and(onHand($from, $variant))->toBe(15)
        ->and(onHand($to, $variant))->toBe(0)
        ->and($movement->type)->toBe(StockMovementType::TransferOut)
        ->and($movement->quantity_change)->toBe(-5)
        ->and($movement->reference_id)->toBe($transfer->id)
        ->and($movement->reference_type)->toBe($transfer->getMorphClass());
});

it('ships nothing at all when one line is short', function () {
    [$from, $to] = transferEnds();
    $plenty = transferVariant($from, 50);
    $scarce = transferVariant($from, 1);
    $transfer = transferAt($from, $to, 'approved', [[$plenty, 5], [$scarce, 4]]);

    expect(fn () => app(ShipStockTransfer::class)->handle($transfer, linesOf($transfer)))
        ->toThrow(ValidationException::class, 'tinggal 1 unit');

    expect(onHand($from, $plenty))->toBe(50)
        ->and(StockMovement::acrossBranches()->count())->toBe(0)
        ->and($transfer->refresh()->status)->toBe(StockTransferStatus::Approved);
});

it('will not ship stock a checkout has already promised a buyer', function () {
    [$from, $to] = transferEnds();
    $variant = transferVariant($from, 10, reserved: 8);
    $transfer = transferAt($from, $to, 'approved', [[$variant, 5]]);

    app(ShipStockTransfer::class)->handle($transfer, linesOf($transfer));
})->throws(ValidationException::class, 'tinggal 2 unit');

it('refuses to ship a transfer nobody has approved', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'requested', [[transferVariant($from, 10), 2]]);

    app(ShipStockTransfer::class)->handle($transfer, linesOf($transfer));
})->throws(ValidationException::class, 'harus disetujui dulu');

it('puts everything that arrived into the destination and closes the transfer', function () {
    [$from, $to] = transferEnds();
    $variant = transferVariant();
    $transfer = transferAt($from, $to, 'in_transit', [[$variant, 4]]);
    $receiver = User::factory()->adminCabang($to->branch)->create();

    $received = app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer), actor: $receiver);

    $movement = StockMovement::acrossBranches()->sole();

    expect($received->status)->toBe(StockTransferStatus::Received)
        ->and($received->received_by)->toBe($receiver->id)
        ->and($received->totalDiscrepancy())->toBe(0)
        ->and(onHand($to, $variant))->toBe(4)
        ->and($movement->type)->toBe(StockMovementType::TransferIn)
        ->and($movement->warehouse_id)->toBe($to->id);
});

it('records a short delivery as a discrepancy rather than reversing it', function () {
    [$from, $to] = transferEnds();
    $intact = transferVariant();
    $damaged = transferVariant();
    $transfer = transferAt($from, $to, 'in_transit', [[$intact, 4], [$damaged, 5]]);

    $received = app(ReceiveStockTransfer::class)->handle(
        $transfer,
        linesOf($transfer, fn (StockTransferItem $item) => $item->product_variant_id === $damaged->id ? 3 : 4),
        receiptNotes: 'Dua dus basah kena hujan',
    );

    expect($received->status)->toBe(StockTransferStatus::PartiallyReceived)
        ->and($received->receipt_notes)->toBe('Dua dus basah kena hujan')
        ->and($received->totalDiscrepancy())->toBe(2)
        ->and(onHand($to, $intact))->toBe(4)
        ->and(onHand($to, $damaged))->toBe(3)
        // The origin is not given the missing units back: they left it.
        ->and(StockMovement::acrossBranches()->where('type', StockMovementType::TransferIn)->count())->toBe(2)
        ->and(StockMovement::acrossBranches()->where('warehouse_id', $from->id)->count())->toBe(0);
});

it('writes no ledger row for a line where nothing arrived', function () {
    [$from, $to] = transferEnds();
    $lost = transferVariant();
    $transfer = transferAt($from, $to, 'in_transit', [[$lost, 2]]);

    app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer, fn () => 0), 'Paket hilang di jalan');

    expect(StockMovement::acrossBranches()->count())->toBe(0)
        ->and($transfer->items()->sole()->discrepancy())->toBe(2);
});

it('asks for an explanation when less arrived than was shipped', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'in_transit', [[transferVariant(), 4]]);

    expect(fn () => app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer, fn () => 3)))
        ->toThrow(ValidationException::class, 'Jelaskan selisihnya');

    expect(StockMovement::acrossBranches()->count())->toBe(0);
});

it('refuses to receive more than was shipped', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'in_transit', [[transferVariant(), 4]]);

    app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer, fn () => 5));
})->throws(ValidationException::class, 'harus antara 0 dan 4');

it('receives a transfer once only', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'in_transit', [[transferVariant(), 4]]);
    app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer));

    app(ReceiveStockTransfer::class)->handle($transfer, linesOf($transfer));
})->throws(ValidationException::class, 'sudah diterima');

it('lets the origin reject until the goods leave, with the reason kept', function (string $step) {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, $step, [[transferVariant(), 2]]);

    $rejected = app(RejectStockTransfer::class)->handle($transfer, 'Stok dipakai untuk pesanan grosir');

    expect($rejected->status)->toBe(StockTransferStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Stok dipakai untuk pesanan grosir');
})->with(['requested', 'approved']);

it('refuses to reject or cancel once the goods are on the road', function (string $action) {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'in_transit', [[transferVariant(), 2]]);

    $action === 'reject'
        ? app(RejectStockTransfer::class)->handle($transfer, 'Terlambat')
        : app(CancelStockTransfer::class)->handle($transfer);
})->with(['reject', 'cancel'])->throws(ValidationException::class, 'Selesaikan dengan penerimaan');

it('lets the destination withdraw its request', function () {
    [$from, $to] = transferEnds();
    $transfer = transferAt($from, $to, 'approved', [[transferVariant(), 2]]);

    expect(app(CancelStockTransfer::class)->handle($transfer)->status)->toBe(StockTransferStatus::Cancelled);
});
