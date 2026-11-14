<?php

namespace App\Actions\Voucher;

use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Support\Facades\DB;

/**
 * Gives an order's voucher back when the order will never be fulfilled
 * (P2-E5-T4).
 *
 * Called for `cancelled` and `expired` (`ReleaseVoucherOfClosedOrder`). The
 * unit of quota and the customer's allowance return; the usage row stays,
 * stamped `released_at`, as the record that it happened. The order keeps its
 * `voucher_code` and `discount_total` — those are what it was sold at.
 *
 * Idempotent: the usage is re-read under the voucher's lock and only a live
 * one is released, so a listener that runs twice gives back one unit.
 */
final class ReleaseVoucher
{
    public function handle(Order $order): bool
    {
        if ($order->voucher_id === null) {
            return false;
        }

        return DB::transaction(function () use ($order): bool {
            $voucher = Voucher::query()->whereKey($order->voucher_id)->lockForUpdate()->first();

            if (! $voucher instanceof Voucher) {
                return false;
            }

            $usage = VoucherUsage::query()
                ->live()
                ->where('voucher_id', $voucher->getKey())
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $usage instanceof VoucherUsage) {
                return false;
            }

            $usage->update(['released_at' => now()]);

            if ($voucher->used_count > 0) {
                $voucher->decrement('used_count');
            }

            return true;
        });
    }
}
