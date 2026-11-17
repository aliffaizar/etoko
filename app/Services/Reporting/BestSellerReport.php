<?php

namespace App\Services\Reporting;

use App\Models\OrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The best-selling variants of a period (P2-E7-T7).
 *
 * Grouped by the SKU on the order line, which is the line's own snapshot
 * (ADR-007): the report names what was sold as it was sold, and a variant
 * deleted since still has its history. Names are the latest snapshot under
 * that SKU.
 *
 * "Best" is units by default — the question a buyer asks when re-ordering —
 * with revenue and order count as the other two sorts.
 */
final class BestSellerReport implements ExportableReport
{
    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'units' => 'units',
        'gross' => 'gross',
        'orders' => 'orders',
    ];

    public const DEFAULT_SORT = 'units';

    public const PER_PAGE = 25;

    public function __construct(private readonly ReportFilters $filters) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(): LengthAwarePaginator
    {
        $page = $this->query()->paginate(self::PER_PAGE)->withQueryString();
        $offset = ($page->currentPage() - 1) * $page->perPage();

        return $page->through(function (OrderItem $row) use (&$offset): array {
            return $this->present($row, ++$offset);
        });
    }

    /**
     * The top of the list, for the dashboard and the chart above the table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function top(int $limit): array
    {
        $rank = 0;

        return $this->query()
            ->limit($limit)
            ->get()
            ->map(function (OrderItem $row) use (&$rank): array {
                return $this->present($row, ++$rank);
            })
            ->all();
    }

    public function rowCount(): int
    {
        return SalesQuery::items($this->filters)->distinct()->count('order_items.sku');
    }

    public function table(): ReportTable
    {
        $rank = 0;

        return new ReportTable(
            title: 'Laporan Produk Terlaris',
            columns: [
                'rank' => ['label' => '#', 'type' => ReportTable::NUMBER],
                'sku' => ['label' => 'SKU', 'type' => ReportTable::TEXT],
                'product_name' => ['label' => 'Produk', 'type' => ReportTable::TEXT],
                'variant_name' => ['label' => 'Varian', 'type' => ReportTable::TEXT],
                'orders' => ['label' => 'Pesanan', 'type' => ReportTable::NUMBER],
                'units' => ['label' => 'Unit terjual', 'type' => ReportTable::NUMBER],
                'returned_units' => ['label' => 'Unit diretur', 'type' => ReportTable::NUMBER],
                'gross' => ['label' => 'Penjualan kotor', 'type' => ReportTable::MONEY],
                'net' => ['label' => 'Penjualan bersih', 'type' => ReportTable::MONEY],
            ],
            rows: $this->query()->lazy(500)->map(function (OrderItem $row) use (&$rank): array {
                return $this->present($row, ++$rank);
            }),
            meta: $this->filters->describe(),
        );
    }

    /**
     * @return Builder<OrderItem>
     */
    private function query(): Builder
    {
        $sort = self::SORTS[$this->filters->sort] ?? self::SORTS[self::DEFAULT_SORT];
        $direction = $this->filters->direction === 'asc' ? 'asc' : 'desc';
        $search = $this->filters->search;

        return SalesQuery::items($this->filters)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('order_items.sku', 'like', "%{$search}%")
                ->orWhere('order_items.product_name', 'like', "%{$search}%")))
            ->select('order_items.sku')
            ->selectRaw('MAX(order_items.product_variant_id) as product_variant_id')
            ->selectRaw('MAX(order_items.product_id) as product_id')
            ->selectRaw('MAX(order_items.product_name) as product_name')
            ->selectRaw('MAX(order_items.variant_name) as variant_name')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.refunded_quantity) as returned_units')
            ->selectRaw('SUM(order_items.subtotal) as gross')
            ->selectRaw('SUM('.SalesQuery::returnedValueSql().') as returned')
            ->groupBy('order_items.sku')
            ->orderBy($sort, $direction)
            ->orderBy('order_items.sku');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderItem $row, int $rank): array
    {
        $gross = (int) $row->getAttribute('gross');

        return [
            'rank' => $rank,
            'sku' => (string) $row->sku,
            'product_id' => $row->getAttribute('product_id') === null ? null : (int) $row->getAttribute('product_id'),
            'product_name' => (string) $row->getAttribute('product_name'),
            'variant_name' => $row->getAttribute('variant_name'),
            'orders' => (int) $row->getAttribute('orders'),
            'units' => (int) $row->getAttribute('units'),
            'returned_units' => (int) $row->getAttribute('returned_units'),
            'gross' => $gross,
            'net' => $gross - (int) $row->getAttribute('returned'),
        ];
    }
}
