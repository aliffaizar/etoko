import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { BranchPerformanceTable } from '@/components/admin/branch-performance-table';
import { ReportToolbar } from '@/components/admin/report-toolbar';
import {
    ReportShell,
    reportBreadcrumbs,
} from '@/components/admin/report-shell';
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
import { useTableQuery } from '@/hooks/use-table-query';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import reports from '@/routes/admin/reports';
import type { BranchPerformanceReportData, ReportPageProps } from '@/types';

const chartConfig = {
    net: { label: 'Penjualan bersih', color: 'var(--chart-2)' },
} satisfies ChartConfig;

/**
 * Branch performance report (P2-E7-T8): sales, average basket, returns,
 * cancellations and share per processing branch (BR-5).
 */
export default function BranchPerformanceReportPage({
    filters,
    report,
    options,
    can,
}: ReportPageProps<BranchPerformanceReportData>) {
    const query = useTableQuery({ only: ['report', 'filters'] });

    return (
        <ReportShell
            reportKey="branches"
            title="Performa Cabang"
            description="Penjualan per cabang pemroses pada periode ini, termasuk cabang yang belum berjualan."
            toolbar={
                <ReportToolbar
                    reportKey="branches"
                    query={query}
                    filters={filters}
                    options={options}
                    can={can}
                />
            }
        >
            {report && (
                <div className="flex flex-col gap-6">
                    {report.rows.length > 1 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Penjualan bersih per cabang
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ChartContainer
                                    config={chartConfig}
                                    className="aspect-auto h-64 w-full"
                                >
                                    <BarChart
                                        data={report.rows}
                                        margin={{ left: 4, right: 12, top: 8 }}
                                    >
                                        <CartesianGrid vertical={false} />
                                        <XAxis
                                            dataKey="branch_code"
                                            tickLine={false}
                                            axisLine={false}
                                            tickMargin={8}
                                        />
                                        <YAxis
                                            tickLine={false}
                                            axisLine={false}
                                            width={72}
                                            tickFormatter={(value: number) =>
                                                formatRupiahCompact(value)
                                            }
                                        />
                                        <ChartTooltip
                                            content={
                                                <ChartTooltipContent
                                                    labelKey="branch_name"
                                                    formatter={(value) => (
                                                        <span className="font-mono tabular-nums">
                                                            {formatRupiah(
                                                                Number(value),
                                                            )}
                                                        </span>
                                                    )}
                                                />
                                            }
                                        />
                                        <Bar
                                            dataKey="net"
                                            fill="var(--color-net)"
                                            radius={4}
                                        />
                                    </BarChart>
                                </ChartContainer>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Rincian per cabang</CardTitle>
                            <CardDescription>
                                Porsi dihitung dari penjualan bersih seluruh
                                cabang pada laporan ini.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <BranchPerformanceTable
                                rows={report.rows}
                                detailed
                            />
                        </CardContent>
                    </Card>
                </div>
            )}
        </ReportShell>
    );
}

BranchPerformanceReportPage.layout = {
    breadcrumbs: reportBreadcrumbs('Performa Cabang', reports.branches()),
};
