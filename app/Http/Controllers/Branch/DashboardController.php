<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;
use App\Notifications\StockTransferNotification;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The branch dashboard (P1-E4-T9).
 *
 * Two things sit at the top because they are the two things that need somebody
 * to act: what has fallen below its minimum, and what is sitting as an
 * unposted draft. Both are read from the tables themselves rather than from
 * the notification feed — a notification is a record of a moment, while the
 * dashboard has to show the situation now.
 *
 * The notification list below them is the record: when each alert fired and
 * what the figure was at the time, which is what makes "why was I told this?"
 * answerable.
 *
 * Every query here is narrowed by the branch global scope, so a branch user
 * only ever sees their own branch (BR-3).
 */
class DashboardController extends Controller
{
    /** Rows shown on the dashboard before it sends people to the full listing. */
    private const PREVIEW_ROWS = 8;

    public function __invoke(): Response
    {
        /** @var User $actor */
        $actor = request()->user();

        return Inertia::render('branch/dashboard', [
            'stats' => [
                'warehouses' => Warehouse::count(),
                'staff' => User::query()->whereNotNull('branch_id')->count(),
                'low_stock' => Stock::query()->low()->count(),
                'open_drafts' => StockEntry::query()->where('status', 'draft')->count()
                    + StockIssue::query()->where('status', 'draft')->count(),
            ],
            'lowStock' => $this->lowStockPreview(),
            'notifications' => $this->notificationFeed($actor),
        ]);
    }

    /**
     * What has fallen to or below its minimum, worst first.
     *
     * "Worst" is the shortfall against the minimum, not the raw quantity: a
     * variant at 0 with a minimum of 1 is less urgent than one at 10 with a
     * minimum of 100, and ordering by quantity would put them the wrong way
     * round.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lowStockPreview(): array
    {
        return Stock::query()
            ->with(['productVariant.product:id,name', 'warehouse:id,name'])
            ->low()
            ->orderByRaw('(min_quantity - quantity) desc')
            ->orderBy('id')
            ->limit(self::PREVIEW_ROWS)
            ->get()
            ->map(fn (Stock $stock): array => [
                'id' => $stock->id,
                'product_variant_id' => $stock->product_variant_id,
                'sku' => $stock->productVariant->sku,
                'product_name' => $stock->productVariant->product->name,
                'warehouse_id' => $stock->warehouse_id,
                'warehouse_name' => $stock->warehouse->name,
                'quantity' => $stock->quantity,
                'min_quantity' => $stock->min_quantity,
                'shortfall' => $stock->min_quantity - $stock->quantity,
            ])
            ->all();
    }

    /**
     * The actor's own low-stock and stock transfer alerts, newest first
     * (P1-E4-T9, P2-E3-T6).
     *
     * Read from the payload the notification stored rather than from `stocks`
     * or `stock_transfers`, because the point of the feed is what was true
     * when it fired. A transfer alert carries `stock_transfer_id`, which the
     * screen turns into a link.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notificationFeed(User $actor): array
    {
        return $actor->notifications()
            ->whereIn('type', [LowStockNotification::class, StockTransferNotification::class])
            ->limit(self::PREVIEW_ROWS)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'is_read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
                ...$notification->data,
            ])
            ->all();
    }
}
