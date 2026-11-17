import { Link } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { DataTable } from '@/components/panel/data-table';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import {
    useTableQuery,
    type UseTableQueryReturn,
} from '@/hooks/use-table-query';
import { formatNumber, formatRupiah } from '@/lib/format';
import reports from '@/routes/admin/reports';
import stocks from '@/routes/branch/stocks';
import type {
    DataTableColumn,
    ReportPageProps,
    StockPositionReportData,
    StockPositionRow,
} from '@/types';

/**
 * Stock position report (P2-E7-T6): what is on the shelves now, per
 * warehouse and variant, valued at cost. `state` narrows to low or empty
 * stock, which is the "stok menipis" report.
 */
export default function StockReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<StockPositionReportData>) {
    const query = useTableQuery({ only: ['report', 'filters'] });

    return (
        <ReportShell
            reportKey="stock"
            title="Posisi Stok"
            description="Stok fisik, yang ditahan checkout, dan nilainya menurut HPP — posisi saat ini, bukan per periode."
            toolbar={
                <ReportToolbar
                    reportKey="stock"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                    withPeriod={false}
                    extraFields={[
                        {
                            type: 'search',
                            key: 'search',
                            placeholder: 'Cari SKU atau produk…',
                        },
                        {
                            type: 'select',
                            key: 'state',
                            label: 'Keadaan',
                            allLabel: 'Semua stok',
                            options: [
                                { value: 'low', label: 'Di bawah minimum' },
                                { value: 'out', label: 'Habis' },
                            ],
                        },
                    ]}
                />
            }
        >
            {report && <StockReportBody report={report} query={query} />}
        </ReportShell>
    );
}

function StockReportBody({
    report,
    query,
}: {
    report: StockPositionReportData;
    query: UseTableQueryReturn;
}) {
    const { summary, rows } = report;

    const columns: DataTableColumn<StockPositionRow>[] = [
        {
            key: 'product',
            header: 'Produk',
            sortKey: 'sku',
            cell: (row) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {row.product_name}
                        {row.variant_name && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {row.variant_name}
                            </span>
                        )}
                    </p>
                    <p className="font-mono text-xs text-muted-foreground">
                        {row.sku}
                    </p>
                </div>
            ),
        },
        {
            key: 'place',
            header: 'Cabang / gudang',
            hideOnMobile: true,
            cell: (row) => (
                <div className="text-sm">
                    <p>{row.branch_name}</p>
                    <p className="text-xs text-muted-foreground">
                        {row.warehouse_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'quantity',
            header: 'Fisik',
            sortKey: 'quantity',
            align: 'right',
            cell: (row) => (
                <div className="flex flex-col items-end gap-1">
                    <span className="tabular-nums">
                        {formatNumber(row.quantity)}
                    </span>
                    {row.is_low && (
                        <StatusBadge label="Menipis" tone="warning" />
                    )}
                </div>
            ),
        },
        {
            key: 'available',
            header: 'Tersedia',
            sortKey: 'available',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="tabular-nums">
                    {formatNumber(row.available_quantity)}
                    {row.reserved_quantity > 0 && (
                        <span className="block text-xs text-muted-foreground">
                            {formatNumber(row.reserved_quantity)} ditahan
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'min',
            header: 'Minimum',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="tabular-nums">
                    {formatNumber(row.min_quantity)}
                </span>
            ),
        },
        {
            key: 'value',
            header: 'Nilai stok',
            sortKey: 'value',
            align: 'right',
            cell: (row) => (
                <span className="whitespace-nowrap tabular-nums">
                    {formatRupiah(row.stock_value)}
                </span>
            ),
        },
        {
            key: 'card',
            header: 'Kartu stok',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) => (
                <Link
                    href={stocks.movements.url([row.product_variant_id], {
                        query: {
                            branch: String(row.branch_id),
                            warehouse: String(row.warehouse_id),
                        },
                    })}
                    className="text-sm underline-offset-4 hover:underline"
                >
                    Riwayat
                </Link>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Unit fisik"
                    value={summary.units}
                    hint={`${formatNumber(summary.reserved)} ditahan checkout`}
                />
                <StatCard
                    label="Nilai stok (HPP)"
                    value={formatRupiah(summary.value)}
                    hint="Varian tanpa HPP dihitung nol."
                />
                <StatCard label="Di bawah minimum" value={summary.low} />
                <StatCard label="Habis" value={summary.out} />
            </div>

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.id}
                paginator={rows}
                sort={query.sort}
                onSortChange={query.toggleSort}
                goToPage={query.goToPage}
                pageUrl={query.pageUrl}
                isLoading={query.isLoading}
                rowLabel="baris stok"
                empty={{
                    title: 'Tidak ada stok yang cocok',
                    description: 'Ubah filter untuk melihat stok lain.',
                    icon: Boxes,
                }}
            />
        </div>
    );
}

StockReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Posisi Stok', reports.stock()),
};
