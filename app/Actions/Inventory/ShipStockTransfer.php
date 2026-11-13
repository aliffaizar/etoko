<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Exceptions\InsufficientStockException;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The origin sends the goods, which is the moment its stock leaves (P2-E3-T2).
 *
 * Each line goes out as `transfer_out` from the origin warehouse, through
 * `StockLedger`, under the row lock — so the shelf is checked when it is
 * emptied, not when the request was approved (BR-6). Stock already promised
 * to a checkout cannot be shipped: the ledger refuses to drop on-hand below
 * what is reserved.
 *
 * The shipper may send less than approved, down to zero on a line, because
 * the shelf can turn out short between agreeing and packing; what they type is
 * what leaves. One short line stops the whole shipment, as one short line
 * stops a goods-out note, so nothing half-leaves.
 *
 * From here until receipt the goods are counted in neither branch.
 */
final class ShipStockTransfer
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<int, array{id: int, quantity: int}>  $items
     *
     * @throws ValidationException
     */
    public function handle(StockTransfer $transfer, array $items, ?User $actor = null): StockTransfer
    {
        $shipped = DB::transaction(function () use ($transfer, $items, $actor): StockTransfer {
            $locked = StockTransferLines::lock($transfer);

            StockTransferLines::refuse($locked->shippingBlockedReason());

            $quantities = StockTransferLines::resolve(
                $locked,
                $items,
                fn (StockTransferItem $item): int => (int) $item->quantity_approved,
                'dikirim',
            );

            if (array_sum($quantities) === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Kirim minimal satu unit. Bila tidak ada yang bisa dikirim, tolak transfernya.',
                ]);
            }

            foreach ($locked->items as $item) {
                $quantity = $quantities[$item->id];

                if ($quantity > 0) {
                    $this->takeFromShelf($locked, $item, $quantity, $actor);
                }

                $item->update(['quantity_shipped' => $quantity]);
            }

            $locked->update([
                'status' => StockTransferStatus::InTransit,
                'shipped_by' => $actor?->getKey(),
                'shipped_at' => now(),
            ]);

            return $locked;
        });

        StockTransferAdvanced::dispatch($shipped, $actor);

        return $shipped;
    }

    /**
     * @throws ValidationException
     */
    private function takeFromShelf(StockTransfer $transfer, StockTransferItem $item, int $quantity, ?User $actor): void
    {
        try {
            $this->ledger->apply(
                warehouse: $transfer->fromWarehouse,
                variant: $item->productVariant,
                type: StockMovementType::TransferOut,
                quantityChange: -$quantity,
                reference: $transfer,
                notes: "Ke {$transfer->toBranch->name} · {$transfer->toWarehouse->name}",
                actor: $actor,
            );
        } catch (InsufficientStockException $shortfall) {
            throw ValidationException::withMessages(['items' => $shortfall->getMessage()]);
        }
    }
}
