<?php

namespace App\Notifications;

use App\Enums\StockTransferStatus;
use App\Models\StockTransfer;
use Illuminate\Notifications\Notification;

/**
 * Tells one end of a stock transfer that the other end has moved it (P2-E3-T6).
 *
 * Stored in the database, like `LowStockNotification`: it is for people who
 * are already in the panel, and it lands in the branch dashboard's feed.
 *
 * The step, the branch names and the figures are copied into the payload, so
 * the feed keeps saying what was true when it fired even after the transfer
 * moves on.
 */
class StockTransferNotification extends Notification
{
    public function __construct(private StockTransfer $transfer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $transfer = $this->transfer->loadMissing(['fromBranch', 'toBranch', 'items']);

        return [
            'type' => 'stock_transfer',
            'stock_transfer_id' => $transfer->id,
            'code' => $transfer->code,
            'status' => $transfer->status->value,
            'from_branch_name' => $transfer->fromBranch->name,
            'to_branch_name' => $transfer->toBranch->name,
            'message' => $this->message($transfer),
        ];
    }

    private function message(StockTransfer $transfer): string
    {
        $from = $transfer->fromBranch->name;
        $to = $transfer->toBranch->name;

        return match ($transfer->status) {
            StockTransferStatus::Requested => "{$to} meminta {$transfer->totalRequested()} unit dari {$from} ({$transfer->code}). Perlu persetujuan.",
            StockTransferStatus::Approved => "Transfer {$transfer->code} dari {$from} ke {$to} disetujui. Barang siap dikirim.",
            StockTransferStatus::Rejected => "Transfer {$transfer->code} ditolak {$from}: {$transfer->rejection_reason}",
            StockTransferStatus::InTransit => "{$from} mengirim {$transfer->items->sum('quantity_shipped')} unit ({$transfer->code}). Terima barangnya saat tiba.",
            StockTransferStatus::Received => "{$to} menerima transfer {$transfer->code} lengkap.",
            StockTransferStatus::PartiallyReceived => "{$to} menerima transfer {$transfer->code} dengan selisih {$transfer->totalDiscrepancy()} unit.",
            StockTransferStatus::Cancelled => "{$to} membatalkan permintaan transfer {$transfer->code}.",
        };
    }
}
