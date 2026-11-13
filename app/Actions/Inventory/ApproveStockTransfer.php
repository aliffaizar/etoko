<?php

namespace App\Actions\Inventory;

use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The origin (or central) agrees to send stock (P2-E3-T2).
 *
 * A promise, not a movement: no ledger row is written and nothing is held
 * (ADR-055). The approver says how much of each line they will send — up to
 * what was asked, zero allowed — and from which of the origin's warehouses.
 */
final class ApproveStockTransfer
{
    /**
     * @param  array{from_warehouse_id?: int|null, items: array<int, array{id: int, quantity: int}>}  $attributes
     *
     * @throws ValidationException
     */
    public function handle(StockTransfer $transfer, array $attributes, ?User $actor = null): StockTransfer
    {
        $approved = DB::transaction(function () use ($transfer, $attributes, $actor): StockTransfer {
            $locked = StockTransferLines::lock($transfer);

            StockTransferLines::refuse($locked->approvalBlockedReason());

            $quantities = StockTransferLines::resolve(
                $locked,
                $attributes['items'],
                fn (StockTransferItem $item): int => $item->quantity_requested,
                'disetujui',
            );

            if (array_sum($quantities) === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Setujui minimal satu unit. Bila tidak ada yang bisa dikirim, tolak permintaannya.',
                ]);
            }

            foreach ($locked->items as $item) {
                $item->update(['quantity_approved' => $quantities[$item->id]]);
            }

            $locked->update([
                'from_warehouse_id' => $this->sourceWarehouse($locked, $attributes['from_warehouse_id'] ?? null)->getKey(),
                'status' => StockTransferStatus::Approved,
                'approved_by' => $actor?->getKey(),
                'approved_at' => now(),
            ]);

            return $locked;
        });

        StockTransferAdvanced::dispatch($approved, $actor);

        return $approved;
    }

    /**
     * @throws ValidationException
     */
    private function sourceWarehouse(StockTransfer $transfer, ?int $warehouseId): Warehouse
    {
        if ($warehouseId === null) {
            return $transfer->fromWarehouse;
        }

        $warehouse = Warehouse::acrossBranches()
            ->whereKey($warehouseId)
            ->where('branch_id', $transfer->from_branch_id)
            ->where('is_active', true)
            ->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => 'Pilih gudang aktif milik cabang asal.',
            ]);
        }

        return $warehouse;
    }
}
