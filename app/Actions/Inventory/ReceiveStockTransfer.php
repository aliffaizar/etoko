<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The destination counts what arrived, which is the moment its stock rises
 * (P2-E3-T2, T4).
 *
 * The receiver types what they actually counted per line — up to what was
 * shipped — and only that enters the destination warehouse, as `transfer_in`.
 *
 * **A shortfall is recorded, not reversed** (ADR-056). The units left the
 * origin when they were shipped; if fewer arrive, the missing ones are in
 * neither warehouse, and that is the truth the ledger should show. The
 * difference is visible per line (`StockTransferItem::discrepancy()`), the
 * transfer closes as `partially_received`, and the receiver must say what
 * happened. Goods that turn up later come in on a goods-in note that names
 * this transfer, rather than reopening a document whose figures are final.
 *
 * Receiving is once: there is no second receipt against the same transfer.
 */
final class ReceiveStockTransfer
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<int, array{id: int, quantity: int}>  $items
     *
     * @throws ValidationException
     */
    public function handle(StockTransfer $transfer, array $items, ?string $receiptNotes = null, ?User $actor = null): StockTransfer
    {
        $received = DB::transaction(function () use ($transfer, $items, $receiptNotes, $actor): StockTransfer {
            $locked = StockTransferLines::lock($transfer);

            StockTransferLines::refuse($locked->receivingBlockedReason());

            $quantities = StockTransferLines::resolve(
                $locked,
                $items,
                fn (StockTransferItem $item): int => (int) $item->quantity_shipped,
                'diterima',
            );

            $isComplete = $locked->items->every(
                fn (StockTransferItem $item): bool => $quantities[$item->id] === $item->quantity_shipped,
            );

            if (! $isComplete && blank($receiptNotes)) {
                throw ValidationException::withMessages([
                    'receipt_notes' => 'Jumlah yang diterima kurang dari yang dikirim. Jelaskan selisihnya: rusak, hilang, atau belum tiba.',
                ]);
            }

            foreach ($locked->items as $item) {
                $quantity = $quantities[$item->id];

                if ($quantity > 0) {
                    $this->ledger->apply(
                        warehouse: $locked->toWarehouse,
                        variant: $item->productVariant,
                        type: StockMovementType::TransferIn,
                        quantityChange: $quantity,
                        reference: $locked,
                        notes: "Dari {$locked->fromBranch->name} · {$locked->fromWarehouse->name}",
                        actor: $actor,
                    );
                }

                $item->update(['quantity_received' => $quantity]);
            }

            $locked->update([
                'status' => $isComplete ? StockTransferStatus::Received : StockTransferStatus::PartiallyReceived,
                'receipt_notes' => blank($receiptNotes) ? null : $receiptNotes,
                'received_by' => $actor?->getKey(),
                'received_at' => now(),
            ]);

            return $locked;
        });

        StockTransferAdvanced::dispatch($received, $actor);

        return $received;
    }
}
