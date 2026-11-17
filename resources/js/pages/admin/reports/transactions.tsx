import { Cell, Pie, PieChart } from 'recharts';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
import { StatCard } from '@/components/panel/stat-card';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatNumber, formatPercent, formatRupiah } from '@/lib/format';
import reports from '@/routes/admin/reports';
import type { ReportPageProps, TransactionReportData } from '@/types';

const SLICE_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

/**
 * Transaction report (P2-E7-T5): payment attempts per method and outcome,
 * orders per status, refunds per method and status. A payment row is one
 * attempt (ADR-038), so a lapsed VA paid again by QRIS is two rows here.
 */
export default function TransactionReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<TransactionReportData>) {
    const query = useTableQuery({ only: ['report', 'filters'] });

    return (
        <ReportShell
            reportKey="transactions"
            title="Transaksi"
            description="Percobaan pembayaran per metode dan status, pesanan per status, dan refund — menurut tanggal dibuat."
            toolbar={
                <ReportToolbar
                    reportKey="transactions"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                    withCategory={false}
                />
            }
        >
            {report && <TransactionReportBody report={report} />}
        </ReportShell>
    );
}

function TransactionReportBody({ report }: { report: TransactionReportData }) {
    const { summary, methods, orders, refunds } = report;
    const slices = methods.filter((method) => method.success_amount > 0);
    const chartConfig: ChartConfig = Object.fromEntries(
        slices.map((method, index) => [
            method.method_name,
            {
                label: method.method_name,
                color: SLICE_COLORS[index % SLICE_COLORS.length],
            },
        ]),
    );

    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Pembayaran berhasil"
                    value={formatRupiah(summary.success_amount)}
                    hint={`${formatNumber(summary.success_count)} transaksi`}
                />
                <StatCard
                    label="Percobaan bayar"
                    value={summary.attempts}
                    hint={
                        summary.attempts === 0
                            ? 'Belum ada percobaan.'
                            : `${formatPercent((summary.success_count / summary.attempts) * 100, 1)} berhasil`
                    }
                />
                <StatCard
                    label="Gagal / kedaluwarsa"
                    value={summary.failed_count}
                />
                <StatCard
                    label="Dana dikembalikan"
                    value={formatRupiah(summary.refunded_amount)}
                    hint="Refund yang sudah diproses."
                />
            </div>

            <div className="grid min-w-0 gap-6 xl:grid-cols-3">
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Porsi per metode</CardTitle>
                        <CardDescription>
                            Nominal pembayaran berhasil.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {slices.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                Belum ada pembayaran berhasil.
                            </p>
                        ) : (
                            <ChartContainer
                                config={chartConfig}
                                className="mx-auto aspect-square max-h-64"
                            >
                                <PieChart>
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                nameKey="method_name"
                                                formatter={(value, name) => (
                                                    <div className="flex w-full justify-between gap-4">
                                                        <span className="text-muted-foreground">
                                                            {name}
                                                        </span>
                                                        <span className="font-mono tabular-nums">
                                                            {formatRupiah(
                                                                Number(value),
                                                            )}
                                                        </span>
                                                    </div>
                                                )}
                                            />
                                        }
                                    />
                                    <Pie
                                        data={slices}
                                        dataKey="success_amount"
                                        nameKey="method_name"
                                        innerRadius={50}
                                        strokeWidth={2}
                                    >
                                        {slices.map((method, index) => (
                                            <Cell
                                                key={method.method_name}
                                                fill={
                                                    SLICE_COLORS[
                                                        index %
                                                            SLICE_COLORS.length
                                                    ]
                                                }
                                            />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ChartContainer>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0 xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Per metode bayar</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {methods.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                Belum ada percobaan pembayaran pada periode ini.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Metode</TableHead>
                                        <TableHead className="text-right">
                                            Percobaan
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Berhasil
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Nominal
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Menunggu
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Gagal
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {methods.map((method) => (
                                        <TableRow
                                            key={`${method.method_name}-${method.method_type}`}
                                        >
                                            <TableCell>
                                                <p className="font-medium">
                                                    {method.method_name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {method.method_type_label}
                                                </p>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(method.attempts)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(
                                                    method.success_count,
                                                )}
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatPercent(
                                                        method.success_rate *
                                                            100,
                                                        1,
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {formatRupiah(
                                                    method.success_amount,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(
                                                    method.pending_count,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(
                                                    method.failed_count,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid min-w-0 gap-6 xl:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Pesanan per status</CardTitle>
                        <CardDescription>
                            Pesanan yang dibuat pada periode ini, menurut
                            statusnya sekarang.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Jumlah
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Nilai
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {orders.map((row) => (
                                    <TableRow key={row.status}>
                                        <TableCell>{row.label}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(row.count)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(row.amount)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Refund</CardTitle>
                        <CardDescription>
                            Per metode dan status.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {refunds.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                Tidak ada refund pada periode ini.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Metode</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Jumlah
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Nominal
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {refunds.map((row) => (
                                        <TableRow
                                            key={`${row.method}-${row.status}`}
                                        >
                                            <TableCell>
                                                {row.method_label}
                                            </TableCell>
                                            <TableCell>
                                                {row.status_label}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.count)}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {formatRupiah(row.amount)}
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

TransactionReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Transaksi', reports.transactions()),
};
