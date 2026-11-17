import { Trophy } from 'lucide-react';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { DataTable } from '@/components/panel/data-table';
import {
    useTableQuery,
    type UseTableQueryReturn,
} from '@/hooks/use-table-query';
import { formatNumber, formatRupiah } from '@/lib/format';
import reports from '@/routes/admin/reports';
import type {
    BestSellerReportData,
    BestSellerRow,
    DataTableColumn,
    ReportPageProps,
} from '@/types';

/**
 * Best-seller report (P2-E7-T7): variants ranked by units sold in the
 * period, by the SKU snapshot on the order line (ADR-007).
 */
export default function BestSellerReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<BestSellerReportData>) {
    const query = useTableQuery({
        only: ['report', 'filters'],
        defaultSort: 'units',
        defaultDirection: 'desc',
    });

    return (
        <ReportShell
            reportKey="products"
            title="Produk Terlaris"
            description="Varian yang paling banyak terjual pada periode ini, menurut SKU di baris pesanan."
            toolbar={
                <ReportToolbar
                    reportKey="products"
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
            {report && <BestSellerBody report={report} query={query} />}
        </ReportShell>
    );
}

function BestSellerBody({
    report,
    query,
}: {
    report: BestSellerReportData;
    query: UseTableQueryReturn;
}) {
    const columns: DataTableColumn<BestSellerRow>[] = [
        {
            key: 'rank',
            header: '#',
            cell: (row) => (
                <span className="text-muted-foreground tabular-nums">
                    {row.rank}
                </span>
            ),
        },
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
                    <p className="font-mono text-xs text-muted-foreground">
                        {row.sku}
                    </p>
                </div>
            ),
        },
        {
            key: 'units',
            header: 'Unit',
            sortKey: 'units',
            align: 'right',
            cell: (row) => (
                <span className="tabular-nums">
                    {formatNumber(row.units)}
                    {row.returned_units > 0 && (
                        <span className="block text-xs text-muted-foreground">
                            {formatNumber(row.returned_units)} diretur
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'orders',
            header: 'Pesanan',
            sortKey: 'orders',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="tabular-nums">{formatNumber(row.orders)}</span>
            ),
        },
        {
            key: 'gross',
            header: 'Penjualan bersih',
            sortKey: 'gross',
            align: 'right',
            cell: (row) => (
                <span className="whitespace-nowrap tabular-nums">
                    {formatRupiah(row.net)}
                </span>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={report.rows.data}
            rowKey={(row) => row.sku}
            paginator={report.rows}
            sort={query.sort}
            onSortChange={query.toggleSort}
            goToPage={query.goToPage}
            pageUrl={query.pageUrl}
            isLoading={query.isLoading}
            rowLabel="varian"
            empty={{
                title: 'Belum ada penjualan',
                description:
                    'Tidak ada barang terjual pada periode dan filter ini.',
                icon: Trophy,
            }}
        />
    );
}

BestSellerReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Produk Terlaris', reports.products()),
};
