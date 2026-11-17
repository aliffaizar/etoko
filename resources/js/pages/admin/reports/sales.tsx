import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { SalesChart } from '@/components/panel/sales-chart';
import { StatCard } from '@/components/panel/stat-card';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatNumber, formatRupiah } from '@/lib/format';
import reports from '@/routes/admin/reports';
import type { ReportPageProps, SalesReportData } from '@/types';

/**
 * Sales report (P2-E7-T4): goods sold over the period, per day/week/month
 * and per category. Gross is what was sold; returns are the value of the
 * units that came back since; net is the difference (ADR-047, ADR-070).
 */
export default function SalesReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<SalesReportData>) {
    const query = useTableQuery({ only: ['report', 'filters'] });

    return (
        <ReportShell
            reportKey="sales"
            title="Penjualan"
            description="Penjualan barang dari pesanan yang sudah dibayar dan tidak dibatalkan, menurut tanggal bayar."
            toolbar={
                <ReportToolbar
                    reportKey="sales"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                    withGroup
                />
            }
        >
            {report && <SalesReportBody report={report} />}
        </ReportShell>
    );
}

function SalesReportBody({ report }: { report: SalesReportData }) {
    const { summary, series, categories } = report;

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Penjualan bersih"
                    value={formatRupiah(summary.net)}
                    hint={`Kotor ${formatRupiah(summary.gross)} · retur ${formatRupiah(summary.returned)}`}
                />
                <StatCard
                    label="Pesanan"
                    value={summary.orders}
                    hint={`${formatNumber(summary.units)} unit terjual`}
                />
                <StatCard
                    label="Rata-rata per pesanan"
                    value={formatRupiah(summary.average_order)}
                    hint="Penjualan kotor dibagi jumlah pesanan."
                />
                <StatCard
                    label="Diskon diberikan"
                    value={formatRupiah(summary.discount)}
                    hint={
                        summary.shipping === null
                            ? 'Ongkir tidak dihitung per kategori.'
                            : `Ongkir ditagihkan ${formatRupiah(summary.shipping)}`
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Grafik penjualan</CardTitle>
                    <CardDescription>
                        Penjualan bersih per periode; garis retur menunjukkan
                        nilai barang yang kembali.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <SalesChart points={series} showReturns />
                </CardContent>
            </Card>

            <div className="grid min-w-0 gap-6 xl:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Per periode</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Periode</TableHead>
                                    <TableHead className="text-right">
                                        Pesanan
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Unit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Bersih
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {series.map((point) => (
                                    <TableRow key={point.key}>
                                        <TableCell className="whitespace-nowrap">
                                            {point.label}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(point.orders)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(point.units)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(point.net)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell>Total</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatNumber(summary.orders)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatNumber(summary.units)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(summary.net)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Per kategori</CardTitle>
                        <CardDescription>
                            Menurut kategori produk saat ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {categories.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                Belum ada penjualan pada periode ini.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Kategori</TableHead>
                                        <TableHead className="text-right">
                                            Unit
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Retur
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Bersih
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {categories.map((row) => (
                                        <TableRow
                                            key={row.category_id ?? 'none'}
                                        >
                                            <TableCell>
                                                {row.category_name}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.units)}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {formatRupiah(row.returned)}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {formatRupiah(row.net)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

SalesReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Penjualan', reports.sales()),
};
