<?php

namespace App\Actions\Inventory;

use App\Enums\StockTransferStatus;
use App\Events\StockTransferAdvanced;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The origin (or central) declines to send stock (P2-E3-T2).
 *
 * Allowed until the goods leave, including after an approval: an origin that
 * agreed and then found the shelf empty must be able to say so, with a reason
 * the destination can read. Nothing had moved, so nothing is reversed.
 */
final class RejectStockTransfer
{
    /**
     * @throws ValidationException
     */
    public function handle(StockTransfer $transfer, string $reason, ?User $actor = null): StockTransfer
    {
        $rejected = DB::transaction(function () use ($transfer, $reason, $actor): StockTransfer {
            $locked = StockTransferLines::lock($transfer);

            StockTransferLines::refuse($locked->rejectionBlockedReason());

            $locked->update([
                'status' => StockTransferStatus::Rejected,
                'rejection_reason' => $reason,
                'rejected_by' => $actor?->getKey(),
                'rejected_at' => now(),
            ]);

            return $locked;
        });

        StockTransferAdvanced::dispatch($rejected, $actor);

        return $rejected;
    }
}
