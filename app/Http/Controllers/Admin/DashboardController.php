<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\Reporting\BranchPerformanceReport;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\SalesQuery;
use App\Services\Reporting\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central dashboard (P2-E7-T1, P2-E7-T2).
 *
 * The counters the brief lists (brief.md §5): branches, products, customers,
 * transactions, sales, incoming orders and low stock — plus the sales chart
 * and the per-branch table, which are the two heavy reads and arrive as
 * deferred props behind a skeleton.
 *
 * Sales and transactions are this month's, with the all-time figure as the
 * hint, and both follow the one definition of a sale in `SalesQuery`
 * (ADR-070), so the dashboard and the sales report agree to the rupiah.
 *
 * "Pesanan masuk" are orders that are paid and waiting for a branch to start
 * on them (`paid`), the same queue the branch notification announces.
 *
 * The deferred reads are cached for five minutes per day
 * (`dash.central.{date}.*`, docs/02-architecture.md §7): the dashboard is the
 * page everyone lands on, and a five-minute-old chart is still the right
 * chart.
 */
class DashboardController extends Controller
{
    /** Rows shown in each preview list before it sends people to the full screen. */
    private const PREVIEW_ROWS = 5;

    private const CACHE_SECONDS = 300;

    public function __invoke(): Response
    {
        $today = CarbonImmutable::now();
        $month = ReportFilters::monthOf($today);
        $sales = (new SalesReport($month))->summary();
        $cacheKey = 'dash.central.'.$today->toDateString();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'branches' => Branch::count(),
                'activeBranches' => Branch::active()->count(),
                'products' => Product::count(),
                'customers' => User::query()->ofRole(UserRole::Customer)->count(),
                'transactions' => $sales['orders'],
                'transactionsAllTime' => SalesQuery::counted()->count(),
                'sales' => $sales['net'],
                'salesAllTime' => $this->allTimeNetSales(),
                'incomingOrders' => Order::query()->where('status', OrderStatus::Paid->value)->count(),
                'lowStock' => Stock::query()->low()->count(),
            ],
            'period' => $month->toArray(),
            'incomingOrders' => $this->incomingOrders(),
            'lowStock' => $this->lowStock(),
            'salesChart' => Inertia::defer(fn (): array => Cache::remember(
                $cacheKey.'.sales-chart',
                self::CACHE_SECONDS,
                fn (): array => (new SalesReport(ReportFilters::lastDays(30, $today)))->series(),
            )),
            'branchPerformance' => Inertia::defer(fn (): array => Cache::remember(
                $cacheKey.'.branch-performance',
                self::CACHE_SECONDS,
                fn (): array => (new BranchPerformanceReport($month))->rows(),
            )),
        ]);
    }

    /**
     * Net goods sales since the shop opened, by the same rule as the report.
     */
    private function allTimeNetSales(): int
    {
        $row = SalesQuery::counted()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->toBase()
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as gross')
            ->selectRaw('COALESCE(SUM('.SalesQuery::returnedValueSql().'), 0) as returned')
            ->first();

        return (int) ($row->gross ?? 0) - (int) ($row->returned ?? 0);
    }

    /**
     * The oldest paid orders still waiting for their branch — oldest first,
     * because the one that has waited longest is the one to chase.
     *
     * @return array<int, array{id: int, order_number: string, customer_name: string, branch_name: string, grand_total: int, paid_at: string|null}>
     */
    private function incomingOrders(): array
    {
        return Order::query()
            ->with('branch:id,name')
            ->where('status', OrderStatus::Paid->value)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->limit(self::PREVIEW_ROWS)
            ->get(['id', 'order_number', 'customer_name', 'branch_id', 'grand_total', 'paid_at'])
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'branch_name' => $order->branch->name,
                'grand_total' => $order->grand_total,
                'paid_at' => $order->paid_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Stock at or below its minimum across every branch, worst shortfall
     * first — the same order as the branch dashboard.
     *
     * @return array<int, array{id: int, branch_id: int, branch_name: string, warehouse_name: string, sku: string, product_name: string, quantity: int, min_quantity: int}>
     */
    private function lowStock(): array
    {
        return Stock::query()
            ->with(['branch:id,name', 'warehouse:id,name', 'productVariant:id,product_id,sku', 'productVariant.product:id,name'])
            ->low()
            ->orderByRaw('(min_quantity - quantity) desc')
            ->orderBy('id')
            ->limit(self::PREVIEW_ROWS)
            ->get()
            ->map(fn (Stock $stock): array => [
                'id' => $stock->id,
                'branch_id' => $stock->branch_id,
                'branch_name' => $stock->branch->name,
                'warehouse_name' => $stock->warehouse->name,
                'sku' => $stock->productVariant->sku,
                'product_name' => $stock->productVariant->product->name,
                'quantity' => $stock->quantity,
                'min_quantity' => $stock->min_quantity,
            ])
            ->all();
    }
}
