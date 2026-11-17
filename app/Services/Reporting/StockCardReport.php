<?php

namespace App\Services\Reporting;

use App\Enums\StockMovementType;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock card over a period, per warehouse and variant: what was there at
 * the start, what came in, what went out, and what was left (P2-E7-T6).
 *
 * Built from the ledger and nothing else (ADR-024). `stock_movements` is
 * append-only and every change to `stocks.quantity` writes one, so the figure
 * at any moment can be worked backwards from the figure now:
 *
 *     closing = quantity now − Σ changes after the period
 *     opening = closing − Σ changes inside the period
 *
 * which needs no stored snapshots and cannot drift from the ledger. Holds
 * taken and released at checkout carry a change of zero (they move
 * `reserved_quantity` only), so they never show here.
 *
 * Only pairs that moved in the period or held something at its end are
 * listed; the rest of the warehouse is a column of zeros. Each row links to
 * the movement screen for the line-by-line card.
 */
final class StockCardReport implements ExportableReport
{
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
            ->through(fn (Stock $row): array => $this->present($row));
    }

    /**
     * @return array{opening: int, in: int, out: int, sold: int, closing: int}
     */
    public function summary(): array
    {
        $row = $this->base()
            ->toBase()
            ->selectRaw('COALESCE(SUM(stocks.quantity - COALESCE(later.change_sum, 0) - COALESCE(period.change_sum, 0)), 0) as opening_sum')
            ->selectRaw('COALESCE(SUM(period.in_sum), 0) as in_sum')
            ->selectRaw('COALESCE(SUM(period.out_sum), 0) as out_sum')
            ->selectRaw('COALESCE(SUM(period.sold_sum), 0) as sold_sum')
            ->selectRaw('COALESCE(SUM(stocks.quantity - COALESCE(later.change_sum, 0)), 0) as closing_sum')
            ->first();

        return [
            'opening' => (int) ($row->opening_sum ?? 0),
            'in' => (int) ($row->in_sum ?? 0),
            'out' => (int) ($row->out_sum ?? 0),
            'sold' => (int) ($row->sold_sum ?? 0),
            'closing' => (int) ($row->closing_sum ?? 0),
        ];
    }

    public function rowCount(): int
    {
        return $this->base()->count();
    }

    public function table(): ReportTable
    {
        $summary = $this->summary();

        return new ReportTable(
            title: 'Laporan Kartu Stok',
            columns: [
                'branch_name' => ['label' => 'Cabang', 'type' => ReportTable::TEXT],
                'warehouse_name' => ['label' => 'Gudang', 'type' => ReportTable::TEXT],
                'sku' => ['label' => 'SKU', 'type' => ReportTable::TEXT],
                'product_name' => ['label' => 'Produk', 'type' => ReportTable::TEXT],
                'opening' => ['label' => 'Awal', 'type' => ReportTable::NUMBER],
                'in' => ['label' => 'Masuk', 'type' => ReportTable::NUMBER],
                'out' => ['label' => 'Keluar', 'type' => ReportTable::NUMBER],
                'sold' => ['label' => 'Terjual', 'type' => ReportTable::NUMBER],
                'closing' => ['label' => 'Akhir', 'type' => ReportTable::NUMBER],
            ],
            rows: $this->query()->lazy(500)->map(fn (Stock $row): array => $this->present($row)),
            meta: $this->filters->describe(),
            totals: [
                'branch_name' => 'Total',
                'opening' => $summary['opening'],
                'in' => $summary['in'],
                'out' => $summary['out'],
                'sold' => $summary['sold'],
                'closing' => $summary['closing'],
            ],
        );
    }

    /**
     * @return Builder<Stock>
     */
    private function query(): Builder
    {
        return $this->base()
            ->select([
                'stocks.id', 'stocks.branch_id', 'stocks.warehouse_id', 'stocks.product_variant_id',
                'stocks.quantity',
                'product_variants.sku', 'product_variants.name as variant_name',
                'products.name as product_name', 'warehouses.name as warehouse_name',
                'branches.name as branch_name',
            ])
            ->selectRaw('COALESCE(period.change_sum, 0) as period_change')
            ->selectRaw('COALESCE(period.in_sum, 0) as in_sum')
            ->selectRaw('COALESCE(period.out_sum, 0) as out_sum')
            ->selectRaw('COALESCE(period.sold_sum, 0) as sold_sum')
            ->selectRaw('COALESCE(later.change_sum, 0) as later_change')
            ->orderBy('branches.name')
            ->orderBy('warehouses.name')
            ->orderBy('product_variants.sku')
            ->orderBy('stocks.id');
    }

    /**
     * Stock rows joined to their movements inside and after the period.
     *
     * @return Builder<Stock>
     */
    private function base(): Builder
    {
        $categoryIds = $this->filters->categoryIds();
        $search = $this->filters->search;
        $saleOut = StockMovementType::SaleOut->value;

        $period = StockMovement::query()
            ->toBase()
            ->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(quantity_change) as change_sum')
            ->selectRaw('SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as in_sum')
            ->selectRaw('SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END) as out_sum')
            ->selectRaw('SUM(CASE WHEN type = ? THEN -quantity_change ELSE 0 END) as sold_sum', [$saleOut])
            ->whereBetween('created_at', [$this->filters->from, $this->filters->until])
            ->groupBy('warehouse_id', 'product_variant_id');

        $later = StockMovement::query()
            ->toBase()
            ->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(quantity_change) as change_sum')
            ->where('created_at', '>', $this->filters->until)
            ->groupBy('warehouse_id', 'product_variant_id');

        return Stock::query()
            ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->join('branches', 'branches.id', '=', 'stocks.branch_id')
            ->leftJoinSub($period, 'period', fn ($join) => $join
                ->on('period.warehouse_id', '=', 'stocks.warehouse_id')
                ->on('period.product_variant_id', '=', 'stocks.product_variant_id'))
            ->leftJoinSub($later, 'later', fn ($join) => $join
                ->on('later.warehouse_id', '=', 'stocks.warehouse_id')
                ->on('later.product_variant_id', '=', 'stocks.product_variant_id'))
            ->where(fn (Builder $moved) => $moved
                ->whereNotNull('period.change_sum')
                ->orWhereRaw('stocks.quantity - COALESCE(later.change_sum, 0) <> 0'))
            ->when($this->filters->branchId !== null, fn (Builder $q) => $q
                ->where('stocks.branch_id', $this->filters->branchId))
            ->when($categoryIds !== null, fn (Builder $q) => $q->whereIn('products.category_id', $categoryIds))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('product_variants.sku', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%")));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Stock $row): array
    {
        $closing = (int) $row->quantity - (int) $row->getAttribute('later_change');

        return [
            'id' => $row->id,
            'branch_name' => $row->getAttribute('branch_name'),
            'warehouse_id' => $row->warehouse_id,
            'warehouse_name' => $row->getAttribute('warehouse_name'),
            'product_variant_id' => $row->product_variant_id,
            'sku' => $row->getAttribute('sku'),
            'product_name' => $row->getAttribute('product_name'),
            'variant_name' => $row->getAttribute('variant_name'),
            'opening' => $closing - (int) $row->getAttribute('period_change'),
            'in' => (int) $row->getAttribute('in_sum'),
            'out' => (int) $row->getAttribute('out_sum'),
            'sold' => (int) $row->getAttribute('sold_sum'),
            'closing' => $closing,
        ];
    }
}
