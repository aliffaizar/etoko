<?php

namespace App\Jobs;

use App\Actions\Product\RefreshLowestPrice;
use App\Models\Promotion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the catalogue price of products whose promotion has just begun or
 * just ended (P2-E8-T2, ADR-066).
 *
 * A promotion's price changes at its boundaries with no request to cause it,
 * so `products.lowest_price` would otherwise stay on the old figure until
 * somebody edited the product. Every five minutes this looks at promotions
 * whose `starts_at` or `ends_at` falls within ten minutes either side of now
 * and refreshes the products they name. The window is twice the schedule, so
 * every boundary is inside at least one run's window.
 */
class SyncPromotionPrices implements ShouldQueue
{
    use Queueable;

    /** Minutes either side of "now" that count as "at a boundary". */
    public const WINDOW_MINUTES = 10;

    public function handle(RefreshLowestPrice $refreshLowestPrice): void
    {
        $now = Carbon::now();
        $from = $now->copy()->subMinutes(self::WINDOW_MINUTES);
        $until = $now->copy()->addMinutes(self::WINDOW_MINUTES);

        $promotionIds = Promotion::query()
            ->where(fn ($boundary) => $boundary
                ->whereBetween('starts_at', [$from, $until])
                ->orWhereBetween('ends_at', [$from, $until]))
            ->pluck('id');

        if ($promotionIds->isEmpty()) {
            return;
        }

        $productIds = DB::table('promotion_products')
            ->whereIn('promotion_id', $promotionIds)
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $refreshLowestPrice->handle($productIds, $now);
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('sync-promotion-prices')];
    }
}
