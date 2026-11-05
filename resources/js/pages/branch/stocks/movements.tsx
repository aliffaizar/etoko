import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { History } from 'lucide-react';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatNumber, formatRupiah } from '@/lib/format';
import stocks from '@/routes/branch/stocks';
import type {
    DataTableColumn,
    FilterField,
    StockMovementRow,
    StockMovementsProps,
} from '@/types';

/** Query keys that name a place rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'warehouse'];

/**
 * One variant's stock history in one warehouse (P1-E4-T8).
 *
 * Read straight from the ledger and never recomputed: the screen exists to
 * explain the figure on the stock listing, and a second calculation of that
 * figure could disagree with it.
 *
 * Each row shows the figure before and after as well as the change, so the
 * column reads as an audit trail rather than a list of deltas — and a gap
 * between one row's "sesudah" and the next row's "sebelum" is visible proof
 * that something wrote stock without going through the ledger.
 */
export default function StockMovements({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    variant,
    stock,
    movements,
    options,
}: StockMovementsProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Stok', href: stocks.index() },
            {
                title: variant.sku,
                href: stocks.movements.url([variant.id]),
            },
        ],
    });

    const table = useTableQuery({
        only: ['movements', 'filters', 'stock', 'warehouse'],
        scopeKeys: SCOPE_KEYS,
    });

    const filterFields: FilterField[] = [
        {
            type: 'select',
            key: 'type',
            label: 'Jenis pergerakan',
            options: options.types,
            allLabel: 'Semua jenis',
            className: 'sm:w-56',
        },
    ];

    const columns: DataTableColumn<StockMovementRow>[] = [
        {
            key: 'created_at',
            header: 'Waktu',
            cell: (row) => (
                <div className="min-w-0">
                    <p className="text-sm">{formatDateTime(row.created_at)}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {row.created_by ?? 'Sistem'}
                    </p>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Jenis',
            cell: (row) => (
                <div className="min-w-0">
                    <p className="text-sm">{row.type_label}</p>
                    {row.reference_label && (
                        <p className="truncate font-mono text-xs text-muted-foreground">
                            {row.reference_label}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'quantity_change',
            header: 'Perubahan',
            align: 'right',
            cell: (row) => (
                <span
                    className={
                        row.quantity_change < 0
                            ? 'text-sm font-medium text-destructive tabular-nums'
                            : 'text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400'
                    }
                >
                    {row.quantity_change > 0 ? '+' : ''}
                    {formatNumber(row.quantity_change)}
                </span>
            ),
        },
        {
            key: 'figures',
            header: 'Sebelum → sesudah',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm text-muted-foreground tabular-nums">
                    {formatNumber(row.quantity_before)} →{' '}
                    <span className="text-foreground">
                        {formatNumber(row.quantity_after)}
                    </span>
                </span>
            ),
        },
        {
            key: 'unit_cost',
            header: 'Harga satuan',
            align: 'right',
            hideOnMobile: true,
            cell: (row) =>
                row.unit_cost === null ? (
                    <span className="text-xs text-muted-foreground">—</span>
                ) : (
                    <span className="text-sm tabular-nums">
                        {formatRupiah(row.unit_cost)}
                    </span>
                ),
        },
        {
            key: 'notes',
            header: 'Catatan',
            cell: (row) => (
                <span className="text-xs text-muted-foreground">
                    {row.notes ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={`Riwayat Stok ${variant.sku}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Riwayat Stok · ${variant.sku}`}
                        description={`${variant.product_name}${variant.name ? ` · ${variant.name}` : ''} — setiap baris mencatat angka sebelum dan sesudahnya, jadi angka stok selalu bisa dijelaskan.`}
                    />
                    <Button variant="outline" asChild>
                        <Link href={stocks.index()}>Kembali ke stok</Link>
                    </Button>
                </div>

                <StockScopePicker
                    branch={branch}
                    warehouse={warehouse}
                    warehouses={warehouses}
                    branches={branches}
                    may_choose_branch={may_choose_branch}
                    onChange={table.setFilters}
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Di gudang" value={stock.on_hand} />
                    <StatCard label="Ditahan checkout" value={stock.reserved} />
                    <StatCard
                        label="Tersedia"
                        value={stock.available}
                        hint="Hanya ini yang boleh dijual."
                    />
                    <StatCard
                        label="Stok minimum"
                        value={stock.min_quantity}
                        hint={
                            stock.is_low
                                ? 'Stok sedang di bawah minimum.'
                                : undefined
                        }
                    />
                </div>

                {stock.is_low && (
                    <div>
                        <StatusBadge
                            label="Stok di bawah minimum"
                            tone="danger"
                        />
                    </div>
                )}

                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <FilterBar
                        fields={filterFields}
                        values={table.values}
                        onChange={table.setFilters}
                        onReset={table.reset}
                        isFiltered={table.isFiltered}
                    />

                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="movement-from"
                                className="text-xs text-muted-foreground"
                            >
                                Dari tanggal
                            </Label>
                            <Input
                                id="movement-from"
                                type="date"
                                className="sm:w-40"
                                value={table.values.from ?? ''}
                                onChange={(event) =>
                                    table.setFilters({
                                        from: event.target.value || null,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="movement-until"
                                className="text-xs text-muted-foreground"
                            >
                                Sampai tanggal
                            </Label>
                            <Input
                                id="movement-until"
                                type="date"
                                className="sm:w-40"
                                value={table.values.until ?? ''}
                                onChange={(event) =>
                                    table.setFilters({
                                        until: event.target.value || null,
                                    })
                                }
                            />
                        </div>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={movements.data}
                    rowKey={(row) => row.id}
                    paginator={movements}
                    rowLabel="pergerakan"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: History,
                        title: table.isFiltered
                            ? 'Tidak ada pergerakan yang cocok'
                            : 'Belum ada pergerakan stok',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat pergerakan lain.'
                            : `Varian ini belum pernah masuk atau keluar dari gudang ${warehouse.name}.`,
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>
        </>
    );
}
