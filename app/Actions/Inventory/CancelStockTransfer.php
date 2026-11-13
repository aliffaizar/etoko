<?php

namespace App\Actions\Inventory;

use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The destination (or central) withdraws its request (P2-E3-T2).
 *
 * "Never mind", as cancelling any stock document is: only reachable while
 * nothing has moved. The transfer stays on the record, cancelled.
 */
final class CancelStockTransfer
{
    /**
     * @throws ValidationException
     */
    public function handle(StockTransfer $transfer, ?User $actor = null): StockTransfer
    {
        $cancelled = DB::transaction(function () use ($transfer, $actor): StockTransfer {
            $locked = StockTransferLines::lock($transfer);

            StockTransferLines::refuse($locked->cancellationBlockedReason());

            $locked->update([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_by' => $actor?->getKey(),
                'cancelled_at' => now(),
            ]);

            return $locked;
        });

        StockTransferAdvanced::dispatch($cancelled, $actor);

        return $cancelled;
    }
}
