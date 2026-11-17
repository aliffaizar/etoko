import { Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { DataTable } from '@/components/panel/data-table';
import { StatCard } from '@/components/panel/stat-card';
import {
    useTableQuery,
    type UseTableQueryReturn,
} from '@/hooks/use-table-query';
import { formatNumber } from '@/lib/format';
import reports from '@/routes/admin/reports';
import stocks from '@/routes/branch/stocks';
import type {
    DataTableColumn,
    ReportPageProps,
    StockCardReportData,
    StockCardRow,
} from '@/types';

/**
 * Stock card report (P2-E7-T6): opening, in, out and closing per warehouse
 * and variant over the period, worked back from the ledger (ADR-024).
 */
export default function StockCardReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<StockCardReportData>) {
    const query = useTableQuery({ only: ['report', 'filters'] });

    return (
        <ReportShell
            reportKey="stock-card"
            title="Kartu Stok"
            description="Saldo awal, masuk, keluar, dan saldo akhir per varian selama periode — dihitung dari riwayat pergerakan stok."
            toolbar={
                <ReportToolbar
                    reportKey="stock-card"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                    extraFields={[
                        {
                            type: 'search',
                            key: 'search',
                            placeholder: 'Cari SKU atau produk…',
                        },
                    ]}
                />
            }
        >
            {report && <StockCardBody report={report} query={query} />}
        </ReportShell>
    );
}

function StockCardBody({
    report,
    query,
}: {
    report: StockCardReportData;
    query: UseTableQueryReturn;
}) {
    const { summary, rows } = report;
    const number = (value: number) => (
        <span className="tabular-nums">{formatNumber(value)}</span>
    );

    const columns: DataTableColumn<StockCardRow>[] = [
        {
            key: 'product',
            header: 'Produk',
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
                    <p className="text-xs text-muted-foreground">
                        <span className="font-mono">{row.sku}</span>
                        {` · ${row.branch_name} / ${row.warehouse_name}`}
                    </p>
                </div>
            ),
        },
        {
            key: 'opening',
            header: 'Awal',
            align: 'right',
            cell: (row) => number(row.opening),
        },
        {
            key: 'in',
            header: 'Masuk',
            align: 'right',
            cell: (row) => number(row.in),
        },
        {
            key: 'out',
            header: 'Keluar',
            align: 'right',
            cell: (row) => number(row.out),
        },
        {
            key: 'sold',
            header: 'Terjual',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => number(row.sold),
        },
        {
            key: 'closing',
            header: 'Akhir',
            align: 'right',
            cell: (row) => (
                <span className="font-medium tabular-nums">
                    {formatNumber(row.closing)}
                </span>
            ),
        },
        {
            key: 'detail',
            header: 'Rincian',
            srOnlyHeader: true,
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <Link
                    href={stocks.movements.url([row.product_variant_id], {
                        query: { warehouse: String(row.warehouse_id) },
                    })}
                    className="text-sm underline-offset-4 hover:underline"
                >
                    Rincian
                </Link>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Saldo awal" value={summary.opening} />
                <StatCard
                    label="Masuk"
                    value={summary.in}
                    hint="Barang masuk, transfer masuk, retur, koreksi naik."
                />
                <StatCard
                    label="Keluar"
                    value={summary.out}
                    hint={`${formatNumber(summary.sold)} di antaranya terjual.`}
                />
                <StatCard label="Saldo akhir" value={summary.closing} />
            </div>

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.id}
                paginator={rows}
                goToPage={query.goToPage}
                pageUrl={query.pageUrl}
                isLoading={query.isLoading}
                rowLabel="varian"
                empty={{
                    title: 'Tidak ada pergerakan stok',
                    description:
                        'Tidak ada varian yang bergerak atau bersisa pada periode ini.',
                    icon: ClipboardList,
                }}
            />
        </div>
    );
}

StockCardReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Kartu Stok', reports.stockCard()),
};
