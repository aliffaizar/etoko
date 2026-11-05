<?php

namespace App\Notifications;

use App\Models\Stock;
use Illuminate\Notifications\Notification;

/**
 * Tells a branch that one of its variants has fallen to its minimum
 * (P1-E4-T9).
 *
 * Stored in the database rather than emailed: it is an operational nudge for
 * people who are already in the panel, and a branch with a long tail of slow
 * movers would otherwise generate a mailbox nobody reads.
 *
 * The figures are **copied into the payload**, not looked up when the
 * notification is read. A notification is a record of what was true when it
 * fired; resolving it later would make yesterday's alert quietly describe
 * today's shelf, and "why was I told this?" would become unanswerable.
 */
class LowStockNotification extends Notification
{
    public function __construct(private Stock $stock) {}

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
        $variant = $this->stock->productVariant;

        return [
            'type' => 'low_stock',
            'branch_id' => $this->stock->branch_id,
            'branch_name' => $this->stock->branch->name,
            'warehouse_id' => $this->stock->warehouse_id,
            'warehouse_name' => $this->stock->warehouse->name,
            'product_variant_id' => $this->stock->product_variant_id,
            'sku' => $variant->sku,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
            'quantity' => $this->stock->quantity,
            'min_quantity' => $this->stock->min_quantity,
            'message' => sprintf(
                'Stok %s di %s tinggal %d unit, di bawah minimum %d.',
                $variant->sku,
                $this->stock->warehouse->name,
                $this->stock->quantity,
                $this->stock->min_quantity,
            ),
        ];
    }
}
