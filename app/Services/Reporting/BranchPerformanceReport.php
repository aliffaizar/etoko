<?php

namespace App\Services\Reporting;

use App\Enums\BranchStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Branch\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * How each branch sold over a period (P2-E7-T2, P2-E7-T8).
 *
 * The same figures as the sales report, split per processing branch (BR-5):
 * goods sold from the order lines, returns beside them, the average basket,
 * and the share of the period's net sales. Cancellations are counted by when
 * they happened (`cancelled_at`), because "how many orders did this branch
 * cancel this month?" is about the month, not about when they were placed.
 *
 * Every branch in scope is listed, those that sold nothing included — a
 * branch at zero is the row a supervisor most needs to see. `Branch` has no
 * global scope (it *is* the branch), so a branch account is narrowed to its
 * own row here explicitly.
 */
final class BranchPerformanceReport implements ExportableReport
{
    public function __construct(private readonly ReportFilters $filters) {}

    /**
     * @return array<int, array{branch_id: int, branch_name: string, branch_code: string, is_active: bool, orders: int, units: int, gross: int, returned: int, net: int, average_order: int, cancelled: int, share: float}>
     */
    public function rows(): array
    {
        $sales = SalesQuery::items($this->filters)
            ->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->select('orders.branch_id')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.subtotal) as gross')
            ->selectRaw('SUM('.SalesQuery::returnedValueSql().') as returned')
            ->groupBy('orders.branch_id')
            ->get()
            ->keyBy('branch_id');

        $cancelled = Order::query()
            ->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$this->filters->from, $this->filters->until])
            ->toBase()
            ->select('branch_id')
            ->selectRaw('COUNT(*) as cancelled')
            ->groupBy('branch_id')
            ->pluck('cancelled', 'branch_id');

        $rows = $this->branches()->map(function (Branch $branch) use ($sales, $cancelled): array {
            $row = $sales->get($branch->id);
            $orders = (int) ($row->orders ?? 0);
            $gross = (int) ($row->gross ?? 0);
            $returned = (int) ($row->returned ?? 0);

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_code' => $branch->code,
                'is_active' => $branch->status === BranchStatus::Active,
                'orders' => $orders,
                'units' => (int) ($row->units ?? 0),
                'gross' => $gross,
                'returned' => $returned,
                'net' => $gross - $returned,
                'average_order' => $orders === 0 ? 0 : intdiv($gross, $orders),
                'cancelled' => (int) ($cancelled[$branch->id] ?? 0),
                'share' => 0.0,
            ];
        });

        $totalNet = $rows->sum('net');

        return $rows
            ->map(fn (array $row): array => [
                ...$row,
                'share' => $totalNet > 0 ? round($row['net'] / $totalNet, 4) : 0.0,
            ])
            ->sortBy([['net', 'desc'], ['branch_name', 'asc']])
            ->values()
            ->all();
    }

    public function rowCount(): int
    {
        return $this->branches()->count();
    }

    public function table(): ReportTable
    {
        $rows = $this->rows();
        $orders = array_sum(array_column($rows, 'orders'));
        $gross = array_sum(array_column($rows, 'gross'));

        return new ReportTable(
            title: 'Laporan Performa Cabang',
            columns: [
                'branch_name' => ['label' => 'Cabang', 'type' => ReportTable::TEXT],
                'branch_code' => ['label' => 'Kode', 'type' => ReportTable::TEXT],
                'orders' => ['label' => 'Pesanan', 'type' => ReportTable::NUMBER],
                'units' => ['label' => 'Unit', 'type' => ReportTable::NUMBER],
                'gross' => ['label' => 'Penjualan kotor', 'type' => ReportTable::MONEY],
                'returned' => ['label' => 'Retur', 'type' => ReportTable::MONEY],
                'net' => ['label' => 'Penjualan bersih', 'type' => ReportTable::MONEY],
                'average_order' => ['label' => 'Rata-rata pesanan', 'type' => ReportTable::MONEY],
                'cancelled' => ['label' => 'Dibatalkan', 'type' => ReportTable::NUMBER],
                'share' => ['label' => 'Porsi', 'type' => ReportTable::PERCENT],
            ],
            rows: $rows,
            meta: $this->filters->describe(),
            totals: [
                'branch_name' => 'Total',
                'branch_code' => '',
                'orders' => $orders,
                'units' => array_sum(array_column($rows, 'units')),
                'gross' => $gross,
                'returned' => array_sum(array_column($rows, 'returned')),
                'net' => array_sum(array_column($rows, 'net')),
                'average_order' => $orders === 0 ? 0 : intdiv($gross, $orders),
                'cancelled' => array_sum(array_column($rows, 'cancelled')),
                'share' => $rows === [] ? 0.0 : 1.0,
            ],
        );
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(): Collection
    {
        $restrictedTo = app(BranchContext::class)->branchId();

        return Branch::query()
            ->when($restrictedTo !== null, fn (Builder $query) => $query->whereKey($restrictedTo))
            ->when($this->filters->branchId !== null, fn (Builder $query) => $query->whereKey($this->filters->branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status']);
    }
}
