<?php

namespace App\Http\Controllers\Branch;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Stock;
use App\Models\StockEntry;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;
use App\Notifications\StockTransferNotification;
use App\Services\Branch\BranchContext;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\SalesReport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
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
 * Below them sit the figures the brief asks of a branch (brief.md §5,
 * P2-E7-T3): this month's sales, orders waiting and in progress, what is on
 * the shelves, and what came in and went out this month. Sales follow the one
 * definition in `SalesQuery` (ADR-070) and are shown only to holders of
 * `reports.sales.view`; the order counts only to staff who handle orders.
 *
 * Every query here is narrowed by the branch global scope, so a branch user
 * only ever sees their own branch (BR-3). Central staff see every branch, or
 * one branch with `?branch=`.
 */
class DashboardController extends Controller
{
    /** Rows shown on the dashboard before it sends people to the full listing. */
    private const PREVIEW_ROWS = 8;

    public function __invoke(Request $request, BranchContext $context): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $branchId = $this->supervisedBranch($request, $actor);
        // `User` carries no branch scope, so the staff count is narrowed by
        // hand to the branch on screen.
        $staffBranchId = $branchId ?? ($actor->isCentral() ? null : $actor->branch_id);
        $today = CarbonImmutable::now();
        $month = ReportFilters::monthOf($today);
        $maySeeSales = $actor->can('reports.sales.view');
        $maySeeOrders = $actor->can('orders.view') || $actor->can('orders.pack');

        return Inertia::render('branch/dashboard', [
            ...$this->scoped($context, $branchId, fn (): array => [
                'stats' => [
                    'warehouses' => Warehouse::count(),
                    'staff' => User::query()->whereNotNull('branch_id')
                        ->when($staffBranchId !== null, fn ($query) => $query->where('branch_id', $staffBranchId))
                        ->count(),
                    'low_stock' => Stock::query()->low()->count(),
                    'open_drafts' => StockEntry::query()->where('status', 'draft')->count()
                        + StockIssue::query()->where('status', 'draft')->count(),
                ],
                'stock' => $this->stockFigures($month),
                'sales' => $maySeeSales ? $this->salesFigures($month) : null,
                'orders' => $maySeeOrders ? $this->orderFigures() : null,
                'lowStock' => $this->lowStockPreview(),
            ])(),
            'notifications' => $this->notificationFeed($actor),
            'salesChart' => $maySeeSales
                ? Inertia::defer($this->scoped(
                    $context,
                    $branchId,
                    fn (): array => (new SalesReport(ReportFilters::lastDays(30, $today)))->series(),
                ))
                : null,
            'period' => $month->toArray(),
            'branchFilter' => [
                'selected' => $branchId === null ? '' : (string) $branchId,
                'options' => $actor->isCentral() ? $this->branchOptions() : [],
            ],
        ]);
    }

    /**
     * A read restricted to the branch on screen.
     *
     * A central account looking at one branch reads it the way that branch's
     * own staff would: restricted, so every query is narrowed by the same
     * global scope (BR-3). Deferred props resolve on a later request, so each
     * closure carries the restriction itself rather than relying on it being
     * in force when the controller returns.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @return Closure(): TValue
     */
    private function scoped(BranchContext $context, ?int $branchId, Closure $read): Closure
    {
        if ($branchId === null) {
            return $read;
        }

        return fn (): mixed => $context->forBranch($branchId, $read);
    }

    /**
     * The branch a central account asked to look at with `?branch=`, or null
     * for "every branch". Branch staff never choose: the middleware has
     * already restricted them to their own.
     */
    private function supervisedBranch(Request $request, User $actor): ?int
    {
        if (! $actor->isCentral() || ! $request->filled('branch')) {
            return null;
        }

        $branchId = $request->integer('branch');

        abort_unless(Branch::query()->whereKey($branchId)->exists(), 404);

        return $branchId;
    }

    /**
     * This month's goods sales, by the same rule as the sales report.
     *
     * @return array{net: int, orders: int, average_order: int}
     */
    private function salesFigures(ReportFilters $month): array
    {
        $summary = (new SalesReport($month))->summary();

        return [
            'net' => $summary['net'],
            'orders' => $summary['orders'],
            'average_order' => $summary['average_order'],
        ];
    }

    /**
     * Orders waiting on the branch: paid and not yet started ("masuk"), and
     * started but not yet out of the door ("diproses").
     *
     * @return array{incoming: int, processing: int}
     */
    private function orderFigures(): array
    {
        $counts = Order::query()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Processing->value])
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'incoming' => (int) ($counts[OrderStatus::Paid->value] ?? 0),
            'processing' => (int) ($counts[OrderStatus::Processing->value] ?? 0),
        ];
    }

    /**
     * What is on the shelves now, and what came in and went out this month,
     * read from the ledger (ADR-024). Holds taken at checkout move nothing
     * and carry a change of zero, so they are not in either figure.
     *
     * @return array{units: int, variants: int, in_month: int, out_month: int}
     */
    private function stockFigures(ReportFilters $month): array
    {
        $onHand = Stock::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(quantity), 0) as units')
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as variants')
            ->first();

        $moved = StockMovement::query()
            ->whereBetween('created_at', [$month->from, $month->until])
            ->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) as moved_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END), 0) as moved_out')
            ->first();

        return [
            'units' => (int) ($onHand->units ?? 0),
            'variants' => (int) ($onHand->variants ?? 0),
            'in_month' => (int) ($moved->moved_in ?? 0),
            'out_month' => (int) ($moved->moved_out ?? 0),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return Branch::query()
            ->ordered()
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => "{$branch->name} ({$branch->code})",
            ])
            ->all();
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
