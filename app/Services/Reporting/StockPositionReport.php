<?php

namespace App\Services\Reporting;

use App\Models\Stock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the stock stands now, per warehouse and variant, with its value at
 * cost (P2-E7-T6).
 *
 * A position is a moment, not a period, so this report ignores the period
 * filter. It lists the `stocks` rows that exist: a variant that never had
 * stock in a warehouse has no row and is zero (ADR-023), and listing every
 * such pair would bury the shelves that matter under thousands of zeros.
 *
 * The value uses the variant's cost price, falling back to the product's — the
 * same rule `ProductVariant::effectiveCostPrice()` follows — and counts a
 * variant with no cost price as zero, which the screen says out loud.
 *
 * `state` narrows to `low` (at or under its minimum, the same rule as
 * `Stock::low()`) or `out` (nothing on hand).
 */
final class StockPositionReport implements ExportableReport
{
    public const STATE_LOW = 'low';

    public const STATE_OUT = 'out';

    /** @var array<int, string> */
    public const STATES = [self::STATE_LOW, self::STATE_OUT];

    /**
     * The columns the listing may be sorted by, as `sort` value => expression.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'sku' => 'product_variants.sku',
        'quantity' => 'stocks.quantity',
        'available' => 'available_quantity',
        'value' => 'stock_value',
    ];

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
     * @return array{rows: int, units: int, reserved: int, value: int, low: int, out: int}
     */
    public function summary(): array
    {
        $row = $this->base(applyState: false)
            ->toBase()
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COALESCE(SUM(stocks.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(stocks.reserved_quantity), 0) as reserved')
            ->selectRaw('COALESCE(SUM(stocks.quantity * '.self::unitCostSql().'), 0) as stock_value')
            ->selectRaw('SUM(CASE WHEN stocks.min_quantity > 0 AND stocks.quantity <= stocks.min_quantity THEN 1 ELSE 0 END) as low_count')
            ->selectRaw('SUM(CASE WHEN stocks.quantity <= 0 THEN 1 ELSE 0 END) as out_count')
            ->first();

        return [
            'rows' => (int) ($row->row_count ?? 0),
            'units' => (int) ($row->units ?? 0),
            'reserved' => (int) ($row->reserved ?? 0),
            'value' => (int) ($row->stock_value ?? 0),
            'low' => (int) ($row->low_count ?? 0),
            'out' => (int) ($row->out_count ?? 0),
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
            title: match ($this->filters->state) {
                self::STATE_LOW => 'Laporan Stok Menipis',
                self::STATE_OUT => 'Laporan Stok Habis',
                default => 'Laporan Posisi Stok',
            },
            columns: [
                'branch_name' => ['label' => 'Cabang', 'type' => ReportTable::TEXT],
                'warehouse_name' => ['label' => 'Gudang', 'type' => ReportTable::TEXT],
                'sku' => ['label' => 'SKU', 'type' => ReportTable::TEXT],
                'product_name' => ['label' => 'Produk', 'type' => ReportTable::TEXT],
                'variant_name' => ['label' => 'Varian', 'type' => ReportTable::TEXT],
                'quantity' => ['label' => 'Fisik', 'type' => ReportTable::NUMBER],
                'reserved_quantity' => ['label' => 'Ditahan', 'type' => ReportTable::NUMBER],
                'available_quantity' => ['label' => 'Tersedia', 'type' => ReportTable::NUMBER],
                'min_quantity' => ['label' => 'Minimum', 'type' => ReportTable::NUMBER],
                'unit_cost' => ['label' => 'HPP', 'type' => ReportTable::MONEY],
                'stock_value' => ['label' => 'Nilai stok', 'type' => ReportTable::MONEY],
            ],
            rows: $this->query()
                ->lazy(500)
                ->map(fn (Stock $row): array => $this->present($row)),
            meta: [
                ['label' => 'Posisi per', 'value' => now()->translatedFormat('j F Y H:i')],
                ...$this->filters->describe(withPeriod: false),
            ],
            totals: [
                'branch_name' => 'Total',
                'quantity' => $summary['units'],
                'reserved_quantity' => $summary['reserved'],
                'available_quantity' => $summary['units'] - $summary['reserved'],
                'stock_value' => $summary['value'],
            ],
        );
    }

    /**
     * The listing query: the base narrowed to the state, sorted.
     *
     * @return Builder<Stock>
     */
    private function query(): Builder
    {
        $column = self::SORTS[$this->filters->sort] ?? null;
        $direction = $this->filters->direction === 'asc' ? 'asc' : 'desc';

        $query = $this->base()
            ->select([
                'stocks.id', 'stocks.branch_id', 'stocks.warehouse_id', 'stocks.product_variant_id',
                'stocks.quantity', 'stocks.reserved_quantity', 'stocks.min_quantity',
                'product_variants.sku', 'product_variants.name as variant_name',
                'products.name as product_name', 'warehouses.name as warehouse_name',
                'branches.name as branch_name',
            ])
            ->selectRaw(self::unitCostSql().' as unit_cost')
            ->selectRaw('stocks.quantity - stocks.reserved_quantity as available_quantity')
            ->selectRaw('stocks.quantity * '.self::unitCostSql().' as stock_value');

        if ($column === null) {
            // Worst first by default when looking at low stock — the shortfall
            // against the minimum, as on the branch dashboard.
            $this->filters->state === self::STATE_LOW
                ? $query->orderByRaw('(stocks.min_quantity - stocks.quantity) desc')
                : $query->orderBy('branches.name')->orderBy('product_variants.sku');
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('stocks.id');
    }

    /**
     * @return Builder<Stock>
     */
    private function base(bool $applyState = true): Builder
    {
        $categoryIds = $this->filters->categoryIds();
        $search = $this->filters->search;

        return Stock::query()
            ->join('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->join('branches', 'branches.id', '=', 'stocks.branch_id')
            ->when($this->filters->branchId !== null, fn (Builder $query) => $query
                ->where('stocks.branch_id', $this->filters->branchId))
            ->when($categoryIds !== null, fn (Builder $query) => $query
                ->whereIn('products.category_id', $categoryIds))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('product_variants.sku', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%")))
            ->when($applyState && $this->filters->state === self::STATE_LOW, fn (Builder $query) => $query
                ->where('stocks.min_quantity', '>', 0)
                ->whereColumn('stocks.quantity', '<=', 'stocks.min_quantity'))
            ->when($applyState && $this->filters->state === self::STATE_OUT, fn (Builder $query) => $query
                ->where('stocks.quantity', '<=', 0));
    }

    /**
     * @return literal-string
     */
    private static function unitCostSql(): string
    {
        return 'COALESCE(product_variants.cost_price, products.cost_price, 0)';
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Stock $row): array
    {
        return [
            'id' => $row->id,
            'branch_id' => $row->branch_id,
            'branch_name' => $row->getAttribute('branch_name'),
            'warehouse_id' => $row->warehouse_id,
            'warehouse_name' => $row->getAttribute('warehouse_name'),
            'product_variant_id' => $row->product_variant_id,
            'sku' => $row->getAttribute('sku'),
            'product_name' => $row->getAttribute('product_name'),
            'variant_name' => $row->getAttribute('variant_name'),
            'quantity' => (int) $row->quantity,
            'reserved_quantity' => (int) $row->reserved_quantity,
            'available_quantity' => (int) $row->getAttribute('available_quantity'),
            'min_quantity' => (int) $row->min_quantity,
            'unit_cost' => (int) $row->getAttribute('unit_cost'),
            'stock_value' => (int) $row->getAttribute('stock_value'),
            'is_low' => $row->min_quantity > 0 && $row->quantity <= $row->min_quantity,
        ];
    }
}
