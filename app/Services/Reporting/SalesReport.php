<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales over a period, per day/week/month and per category (P2-E7-T4).
 *
 * Read from the order **lines**, not the order headers: a category filter is a
 * question about lines ("how much audio did we sell?"), and answering it from
 * headers would count a whole basket because one cable in it was audio. The
 * line `subtotal` already carries its share of every discount (ADR-064), so
 * the lines of an order add up to what the customer paid for goods.
 *
 * Shipping is not a sale of goods and is reported beside the figures, only
 * when no category is asked for — a parcel cannot be split between the
 * categories inside it.
 *
 * Gross and net are both stated (ADR-047): gross is what was sold, `returned`
 * is the value of the units that came back since, and net is the difference.
 */
final class SalesReport implements ExportableReport
{
    public function __construct(private readonly ReportFilters $filters) {}

    /**
     * @return array{gross: int, discount: int, returned: int, net: int, orders: int, units: int, average_order: int, shipping: int|null}
     */
    public function summary(): array
    {
        $row = SalesQuery::items($this->filters)
            ->toBase()
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as gross')
            ->selectRaw('COALESCE(SUM(order_items.discount_amount), 0) as discount')
            ->selectRaw('COALESCE(SUM('.SalesQuery::returnedValueSql().'), 0) as returned')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->first();

        $gross = (int) ($row->gross ?? 0);
        $orders = (int) ($row->orders ?? 0);

        return [
            'gross' => $gross,
            'discount' => (int) ($row->discount ?? 0),
            'returned' => (int) ($row->returned ?? 0),
            'net' => $gross - (int) ($row->returned ?? 0),
            'orders' => $orders,
            'units' => (int) ($row->units ?? 0),
            'average_order' => $orders === 0 ? 0 : intdiv($gross, $orders),
            'shipping' => $this->filters->categoryId === null
                ? (int) SalesQuery::orders($this->filters)->sum('orders.shipping_cost')
                : null,
        ];
    }

    /**
     * One point per bucket of the period, empty buckets included.
     *
     * Grouped by day in SQL and folded into weeks or months here: `DATE()` is
     * the one date function MySQL and SQLite share, and a period holds at most
     * a few hundred days. Each order has exactly one `paid_at`, so adding up
     * the daily order counts never counts an order twice.
     *
     * @return array<int, array{key: string, label: string, gross: int, returned: int, net: int, orders: int, units: int}>
     */
    public function series(): array
    {
        $day = SalesQuery::daySql('orders.paid_at');

        $days = SalesQuery::items($this->filters)
            ->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw("{$day} as day")
            ->selectRaw('SUM(order_items.subtotal) as gross')
            ->selectRaw('SUM('.SalesQuery::returnedValueSql().') as returned')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->groupByRaw($day)
            ->get();

        $points = [];

        foreach ($this->filters->buckets() as $bucket) {
            $points[$bucket['key']] = [
                ...$bucket,
                'gross' => 0, 'returned' => 0, 'net' => 0, 'orders' => 0, 'units' => 0,
            ];
        }

        foreach ($days as $row) {
            $key = $this->filters->bucketKey(CarbonImmutable::parse((string) $row->day));

            if (! isset($points[$key])) {
                continue;
            }

            $points[$key]['gross'] += (int) $row->gross;
            $points[$key]['returned'] += (int) $row->returned;
            $points[$key]['net'] += (int) $row->gross - (int) $row->returned;
            $points[$key]['orders'] += (int) $row->orders;
            $points[$key]['units'] += (int) $row->units;
        }

        return array_values($points);
    }

    /**
     * Sales per category of the product sold, largest first.
     *
     * The category is the product's **current** one: order lines snapshot
     * name and price (ADR-007) but not the category, so a product moved to
     * another category moves its history with it. A line whose product was
     * deleted lands under "Tanpa kategori".
     *
     * @return array<int, array{category_id: int|null, category_name: string, orders: int, units: int, gross: int, returned: int, net: int}>
     */
    public function byCategory(): array
    {
        return SalesQuery::items($this->filters)
            ->toBase()
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select('categories.id as category_id')
            ->selectRaw('MAX(categories.name) as category_name')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.subtotal) as gross')
            ->selectRaw('SUM('.SalesQuery::returnedValueSql().') as returned')
            ->groupBy('categories.id')
            ->orderByDesc(DB::raw('SUM(order_items.subtotal)'))
            ->get()
            ->map(fn (object $row): array => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category_name' => $row->category_name ?? 'Tanpa kategori',
                'orders' => (int) $row->orders,
                'units' => (int) $row->units,
                'gross' => (int) $row->gross,
                'returned' => (int) $row->returned,
                'net' => (int) $row->gross - (int) $row->returned,
            ])
            ->all();
    }

    public function rowCount(): int
    {
        return count($this->filters->buckets());
    }

    public function table(): ReportTable
    {
        $summary = $this->summary();

        return new ReportTable(
            title: 'Laporan Penjualan',
            columns: [
                'label' => ['label' => 'Periode', 'type' => ReportTable::TEXT],
                'orders' => ['label' => 'Pesanan', 'type' => ReportTable::NUMBER],
                'units' => ['label' => 'Unit', 'type' => ReportTable::NUMBER],
                'gross' => ['label' => 'Penjualan kotor', 'type' => ReportTable::MONEY],
                'returned' => ['label' => 'Retur', 'type' => ReportTable::MONEY],
                'net' => ['label' => 'Penjualan bersih', 'type' => ReportTable::MONEY],
            ],
            rows: array_map(fn (array $point): array => [
                'label' => $point['label'],
                'orders' => $point['orders'],
                'units' => $point['units'],
                'gross' => $point['gross'],
                'returned' => $point['returned'],
                'net' => $point['net'],
            ], $this->series()),
            meta: $this->filters->describe(),
            totals: [
                'label' => 'Total',
                'orders' => $summary['orders'],
                'units' => $summary['units'],
                'gross' => $summary['gross'],
                'returned' => $summary['returned'],
                'net' => $summary['net'],
            ],
        );
    }
}
