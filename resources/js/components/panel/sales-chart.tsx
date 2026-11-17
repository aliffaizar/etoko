import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import type { SalesPoint } from '@/types';

const chartConfig = {
    net: { label: 'Penjualan bersih', color: 'var(--chart-2)' },
    returned: { label: 'Retur', color: 'var(--chart-1)' },
} satisfies ChartConfig;

/**
 * Sales over time as an area chart, shared by both dashboards and the sales
 * report (P2-E7-T1, P2-E7-T4).
 *
 * Every bucket of the period is a point, empty ones included, so a quiet day
 * reads as zero instead of as a gap (`ReportFilters::buckets()`).
 */
export function SalesChart({
    points,
    showReturns = false,
    className = 'aspect-auto h-64 w-full',
}: {
    points: SalesPoint[];
    showReturns?: boolean;
    className?: string;
}) {
    return (
        <ChartContainer config={chartConfig} className={className}>
            <AreaChart data={points} margin={{ left: 4, right: 12, top: 8 }}>
                <defs>
                    <linearGradient id="fill-net" x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="5%"
                            stopColor="var(--color-net)"
                            stopOpacity={0.6}
                        />
                        <stop
                            offset="95%"
                            stopColor="var(--color-net)"
                            stopOpacity={0.05}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="label"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    minTickGap={24}
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
                            formatter={(value, name) => (
                                <div className="flex w-full justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        {chartConfig[
                                            name as keyof typeof chartConfig
                                        ]?.label ?? name}
                                    </span>
                                    <span className="font-mono font-medium tabular-nums">
                                        {formatRupiah(Number(value))}
                                    </span>
                                </div>
                            )}
                        />
                    }
                />
                <Area
                    dataKey="net"
                    type="monotone"
                    fill="url(#fill-net)"
                    stroke="var(--color-net)"
                    strokeWidth={2}
                />
                {showReturns && (
                    <Area
                        dataKey="returned"
                        type="monotone"
                        fill="var(--color-returned)"
                        fillOpacity={0.15}
                        stroke="var(--color-returned)"
                        strokeWidth={1.5}
                    />
                )}
                {showReturns && (
                    <ChartLegend content={<ChartLegendContent />} />
                )}
            </AreaChart>
        </ChartContainer>
    );
}

/** Placeholder while a deferred chart loads. */
export function ChartSkeleton({
    className = 'h-64 w-full',
}: {
    className?: string;
}) {
    return <Skeleton className={className} />;
}
