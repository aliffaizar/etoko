<?php

namespace App\Actions\Inventory;

use App\Enums\BranchStatus;
use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The destination branch asks another branch for stock (P2-E3-T2).
 *
 * Nothing moves. The origin's default warehouse is named as the source; the
 * origin may point at another of its warehouses when it approves, because the
 * requester has no business knowing how the origin arranges its shelves.
 *
 * Availability at the origin is not checked, for the same reason a goods-out
 * draft does not check it: the figure moves before anyone ships, and the
 * check that counts is the one under the lock (BR-6).
 */
final class RequestStockTransfer
{
    /**
     * @param  array{notes?: string|null, items: array<int, array{product_variant_id: int, quantity: int}>}  $attributes
     *
     * @throws ValidationException When the origin cannot be asked.
     */
    public function handle(Warehouse $toWarehouse, Branch $fromBranch, array $attributes, ?User $actor = null): StockTransfer
    {
        $fromWarehouse = $this->sourceWarehouse($toWarehouse, $fromBranch);

        // Before the transaction, as every document does (ADR-037).
        $code = DocumentNumber::next(DocumentNumber::PREFIX_STOCK_TRANSFER);

        $transfer = DB::transaction(function () use ($toWarehouse, $fromWarehouse, $attributes, $actor, $code): StockTransfer {
            $transfer = StockTransfer::create([
                'code' => $code,
                'from_branch_id' => $fromWarehouse->branch_id,
                'from_warehouse_id' => $fromWarehouse->getKey(),
                'to_branch_id' => $toWarehouse->branch_id,
                'to_warehouse_id' => $toWarehouse->getKey(),
                'status' => StockTransferStatus::Requested,
                'notes' => $attributes['notes'] ?? null,
                'requested_by' => $actor?->getKey(),
                'requested_at' => now(),
            ]);

            StockTransferItem::insert(array_map(fn (array $item): array => [
                'stock_transfer_id' => $transfer->getKey(),
                'product_variant_id' => $item['product_variant_id'],
                'quantity_requested' => $item['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes['items']));

            return $transfer;
        });

        StockTransferAdvanced::dispatch($transfer, $actor);

        return $transfer;
    }

    /**
     * @throws ValidationException
     */
    private function sourceWarehouse(Warehouse $toWarehouse, Branch $fromBranch): Warehouse
    {
        if ($fromBranch->getKey() === $toWarehouse->branch_id) {
            throw ValidationException::withMessages([
                'from_branch_id' => 'Cabang asal harus cabang lain. Memindahkan stok antar gudang satu cabang belum didukung.',
            ]);
        }

        if (! $toWarehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => "Gudang {$toWarehouse->name} nonaktif, jadi tidak bisa menerima barang.",
            ]);
        }

        if ($fromBranch->status !== BranchStatus::Active) {
            throw ValidationException::withMessages([
                'from_branch_id' => "Cabang {$fromBranch->name} sedang {$fromBranch->status->label()}, jadi tidak bisa dimintai stok.",
            ]);
        }

        $warehouse = Warehouse::acrossBranches()
            ->where('branch_id', $fromBranch->getKey())
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'from_branch_id' => "Cabang {$fromBranch->name} belum punya gudang aktif.",
            ]);
        }

        return $warehouse;
    }
}
