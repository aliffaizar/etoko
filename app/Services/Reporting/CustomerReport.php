<?php

namespace App\Services\Reporting;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who bought in a period, and how much (P2-E7-T7).
 *
 * Read from the orders that count as sales (`SalesQuery`), grouped by the
 * customer account. The name and e-mail are the orders' snapshots, so the
 * report says who placed them as they were placed.
 *
 * Because `Order` carries the branch global scope, a branch admin sees exactly
 * the customers who bought **from their branch** and only what they spent
 * there — the same narrowing `User::customersVisibleTo()` applies to the
 * customer listing (BR-3). Orders without an account (POS walk-ins) cannot be
 * grouped by person; they are counted in the summary as `guest_orders`.
 *
 * A **new** customer is one whose first counted order falls inside the
 * period, judged against every counted order they ever placed in scope — not
 * one whose account was made in the period, which says nothing about buying.
 */
final class CustomerReport implements ExportableReport
{
    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'spent' => 'spent',
        'orders' => 'orders',
        'last_order_at' => 'last_order_at',
    ];

    public const DEFAULT_SORT = 'spent';

    public const PER_PAGE = 25;

    public function __construct(private readonly ReportFilters $filters) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(): LengthAwarePaginator
    {
        return $this->query()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $row): array => $this->present($row));
    }

    /**
     * @return array{customers: int, new_customers: int, returning_customers: int, spent: int, average_spent: int, guest_orders: int}
     */
    public function summary(): array
    {
        $perCustomer = SalesQuery::orders($this->filters)
            ->whereNotNull('orders.user_id')
            ->toBase()
            ->select('orders.user_id')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(orders.grand_total) as spent')
            ->groupBy('orders.user_id');

        $row = DB::query()
            ->fromSub($perCustomer, 'per_customer')
            ->selectRaw('COUNT(*) as customers')
            ->selectRaw('SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) as returning_customers')
            ->selectRaw('COALESCE(SUM(spent), 0) as spent')
            ->first();

        $customers = (int) ($row->customers ?? 0);
        $spent = (int) ($row->spent ?? 0);

        return [
            'customers' => $customers,
            'new_customers' => $this->newCustomerCount(),
            'returning_customers' => (int) ($row->returning_customers ?? 0),
            'spent' => $spent,
            'average_spent' => $customers === 0 ? 0 : intdiv($spent, $customers),
            'guest_orders' => SalesQuery::orders($this->filters)->whereNull('orders.user_id')->count(),
        ];
    }

    public function rowCount(): int
    {
        return SalesQuery::orders($this->filters)
            ->whereNotNull('orders.user_id')
            ->distinct()
            ->count('orders.user_id');
    }

    public function table(): ReportTable
    {
        return new ReportTable(
            title: 'Laporan Pelanggan',
            columns: [
                'customer_name' => ['label' => 'Pelanggan', 'type' => ReportTable::TEXT],
                'customer_email' => ['label' => 'Email', 'type' => ReportTable::TEXT],
                'orders' => ['label' => 'Pesanan', 'type' => ReportTable::NUMBER],
                'spent' => ['label' => 'Total belanja', 'type' => ReportTable::MONEY],
                'average_order' => ['label' => 'Rata-rata per pesanan', 'type' => ReportTable::MONEY],
                'first_order_at' => ['label' => 'Pesanan pertama', 'type' => ReportTable::TEXT],
                'last_order_at' => ['label' => 'Pesanan terakhir', 'type' => ReportTable::TEXT],
            ],
            rows: $this->query()->lazy(500)->map(fn (Order $row): array => $this->present($row)),
            meta: $this->filters->describe(),
        );
    }

    /**
     * Customers whose first counted order in scope lies inside the period.
     */
    private function newCustomerCount(): int
    {
        $everFirst = SalesQuery::counted($this->filters->branchId)
            ->whereNotNull('orders.user_id')
            ->toBase()
            ->select('orders.user_id')
            ->selectRaw('MIN(orders.paid_at) as first_paid_at')
            ->groupBy('orders.user_id');

        return DB::query()
            ->fromSub($everFirst, 'firsts')
            ->whereBetween('first_paid_at', [$this->filters->from, $this->filters->until])
            ->count();
    }

    /**
     * @return Builder<Order>
     */
    private function query(): Builder
    {
        $sort = self::SORTS[$this->filters->sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $this->filters->direction === 'asc' ? 'asc' : 'desc';
        $search = $this->filters->search;

        return SalesQuery::orders($this->filters)
            ->whereNotNull('orders.user_id')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('orders.customer_name', 'like', "%{$search}%")
                ->orWhere('orders.customer_email', 'like', "%{$search}%")))
            ->select('orders.user_id')
            ->selectRaw('MAX(orders.customer_name) as customer_name')
            ->selectRaw('MAX(orders.customer_email) as customer_email')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(orders.grand_total) as spent')
            ->selectRaw('MIN(orders.paid_at) as first_order_at')
            ->selectRaw('MAX(orders.paid_at) as last_order_at')
            ->groupBy('orders.user_id')
            ->orderBy($sort, $direction)
            ->orderBy('orders.user_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $row): array
    {
        $orders = (int) $row->getAttribute('orders');
        $spent = (int) $row->getAttribute('spent');

        return [
            'user_id' => (int) $row->user_id,
            'customer_name' => (string) $row->getAttribute('customer_name'),
            'customer_email' => (string) $row->getAttribute('customer_email'),
            'orders' => $orders,
            'spent' => $spent,
            'average_order' => $orders === 0 ? 0 : intdiv($spent, $orders),
            'first_order_at' => (string) $row->getRawOriginal('first_order_at'),
            'last_order_at' => (string) $row->getRawOriginal('last_order_at'),
        ];
    }
}
