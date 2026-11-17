import { Link } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { DataTable } from '@/components/panel/data-table';
import { StatCard } from '@/components/panel/stat-card';
import { useAuthorization } from '@/hooks/use-authorization';
import {
    useTableQuery,
    type UseTableQueryReturn,
} from '@/hooks/use-table-query';
import { formatDate, formatNumber, formatRupiah } from '@/lib/format';
import { show as customerShow } from '@/routes/admin/customers';
import reports from '@/routes/admin/reports';
import type {
    CustomerReportData,
    CustomerReportRow,
    DataTableColumn,
    ReportPageProps,
} from '@/types';

/**
 * Customer report (P2-E7-T7): who bought in the period and how much. A
 * branch admin sees only what customers spent at their branch (BR-3).
 */
export default function CustomerReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<CustomerReportData>) {
    const query = useTableQuery({
        only: ['report', 'filters'],
        defaultSort: 'spent',
        defaultDirection: 'desc',
    });

    return (
        <ReportShell
            reportKey="customers"
            title="Pelanggan"
            description="Pelanggan yang berbelanja pada periode ini, jumlah pesanan, dan total belanjanya (termasuk ongkir)."
            toolbar={
                <ReportToolbar
                    reportKey="customers"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                    withCategory={false}
                    extraFields={[
                        {
                            type: 'search',
                            key: 'search',
                            placeholder: 'Cari nama atau email…',
                        },
                    ]}
                />
            }
        >
            {report && <CustomerReportBody report={report} query={query} />}
        </ReportShell>
    );
}

function CustomerReportBody({
    report,
    query,
}: {
    report: CustomerReportData;
    query: UseTableQueryReturn;
}) {
    const { can } = useAuthorization();
    const { summary, rows } = report;

    const columns: DataTableColumn<CustomerReportRow>[] = [
        {
            key: 'customer',
            header: 'Pelanggan',
            cell: (row) => (
                <div className="min-w-0">
                    {can('customers.view') ? (
                        <Link
                            href={customerShow(row.user_id)}
                            className="truncate font-medium underline-offset-4 hover:underline"
                        >
                            {row.customer_name}
                        </Link>
                    ) : (
                        <p className="truncate font-medium">
                            {row.customer_name}
                        </p>
                    )}
                    <p className="truncate text-xs text-muted-foreground">
                        {row.customer_email}
                    </p>
                </div>
            ),
        },
        {
            key: 'orders',
            header: 'Pesanan',
            sortKey: 'orders',
            align: 'right',
            cell: (row) => (
                <span className="tabular-nums">{formatNumber(row.orders)}</span>
            ),
        },
        {
            key: 'spent',
            header: 'Total belanja',
            sortKey: 'spent',
            align: 'right',
            cell: (row) => (
                <span className="whitespace-nowrap tabular-nums">
                    {formatRupiah(row.spent)}
                    <span className="block text-xs text-muted-foreground">
                        rata-rata {formatRupiah(row.average_order)}
                    </span>
                </span>
            ),
        },
        {
            key: 'last_order_at',
            header: 'Terakhir belanja',
            sortKey: 'last_order_at',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="whitespace-nowrap">
                    {formatDate(row.last_order_at)}
                </span>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Pelanggan berbelanja"
                    value={summary.customers}
                    hint={`${formatNumber(summary.returning_customers)} belanja lebih dari sekali`}
                />
                <StatCard
                    label="Pelanggan baru"
                    value={summary.new_customers}
                    hint="Pembelian pertamanya jatuh di periode ini."
                />
                <StatCard
                    label="Total belanja"
                    value={formatRupiah(summary.spent)}
                    hint={`Rata-rata ${formatRupiah(summary.average_spent)} per pelanggan`}
                />
                <StatCard
                    label="Pesanan tanpa akun"
                    value={summary.guest_orders}
                    hint="Tidak masuk daftar per pelanggan."
                />
            </div>

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.user_id}
                paginator={rows}
                sort={query.sort}
                onSortChange={query.toggleSort}
                goToPage={query.goToPage}
                pageUrl={query.pageUrl}
                isLoading={query.isLoading}
                rowLabel="pelanggan"
                empty={{
                    title: 'Belum ada pelanggan berbelanja',
                    description:
                        'Tidak ada pesanan terbayar pada periode dan filter ini.',
                    icon: UserRound,
                }}
            />
        </div>
    );
}

CustomerReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Pelanggan', reports.customers()),
};
