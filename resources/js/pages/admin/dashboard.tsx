import { Deferred, Head, Link } from '@inertiajs/react';
import {
    Boxes,
    Building2,
    Inbox,
    Package,
    Receipt,
    TrendingUp,
    TriangleAlert,
    UserRound,
} from 'lucide-react';
import { BranchPerformanceTable } from '@/components/admin/branch-performance-table';
import Heading from '@/components/heading';
import { ChartSkeleton, SalesChart } from '@/components/panel/sales-chart';
import { StatCard } from '@/components/panel/stat-card';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useAuthorization } from '@/hooks/use-authorization';
import {
    formatDate,
    formatNumber,
    formatRelative,
    formatRupiah,
} from '@/lib/format';
import { dashboard } from '@/routes/admin';
import { index as ordersIndex, show as orderShow } from '@/routes/admin/orders';
import reports from '@/routes/admin/reports';
import type { BranchPerformanceRow, ReportFilters, SalesPoint } from '@/types';

type DashboardProps = {
    stats: {
        branches: number;
        activeBranches: number;
        products: number;
        customers: number;
        transactions: number;
        transactionsAllTime: number;
        sales: number;
        salesAllTime: number;
        incomingOrders: number;
        lowStock: number;
    };
    period: ReportFilters;
    incomingOrders: {
        id: number;
        order_number: string;
        customer_name: string;
        branch_name: string;
        grand_total: number;
        paid_at: string | null;
    }[];
    lowStock: {
        id: number;
        branch_id: number;
        branch_name: string;
        warehouse_name: string;
        sku: string;
        product_name: string;
        quantity: number;
        min_quantity: number;
    }[];
    /** Deferred. */
    salesChart?: SalesPoint[];
    /** Deferred. */
    branchPerformance?: BranchPerformanceRow[];
};

/**
 * The central dashboard (P2-E7-T1, P2-E7-T2): the brief's counters, the
 * paid orders still waiting for a branch, the worst low stock, a 30-day
 * sales chart and this month's figures per branch. Sales follow the same
 * rule as the sales report (ADR-070).
 */
export default function AdminDashboard({
    stats,
    period,
    incomingOrders,
    lowStock,
    salesChart,
    branchPerformance,
}: DashboardProps) {
    const { can } = useAuthorization();
    const monthLabel = `${formatDate(period.from)} – ${formatDate(period.until)}`;

    return (
        <>
            <Head title="Dashboard Pusat" />

            <div className="flex h-full min-w-0 flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Dashboard Pusat"
                    description="Ringkasan seluruh cabang dan operasional toko."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Penjualan bulan ini"
                        value={formatRupiah(stats.sales)}
                        hint={`Sepanjang waktu ${formatRupiah(stats.salesAllTime)}`}
                        icon={TrendingUp}
                    />
                    <StatCard
                        label="Transaksi bulan ini"
                        value={stats.transactions}
                        hint={`Sepanjang waktu ${formatNumber(stats.transactionsAllTime)}`}
                        icon={Receipt}
                    />
                    <StatCard
                        label="Pesanan masuk"
                        value={stats.incomingOrders}
                        hint="Sudah dibayar, belum diproses cabang."
                        icon={Inbox}
                    />
                    <StatCard
                        label="Stok menipis"
                        value={stats.lowStock}
                        hint="Di bawah minimum, semua cabang."
                        icon={TriangleAlert}
                    />
                    <StatCard
                        label="Total cabang"
                        value={stats.branches}
                        hint={`${formatNumber(stats.activeBranches)} cabang aktif`}
                        icon={Building2}
                    />
                    <StatCard
                        label="Total produk"
                        value={stats.products}
                        icon={Package}
                    />
                    <StatCard
                        label="Total pelanggan"
                        value={stats.customers}
                        icon={UserRound}
                    />
                </div>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Grafik penjualan</CardTitle>
                        <CardDescription>
                            Penjualan bersih harian, 30 hari terakhir.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Deferred
                            data="salesChart"
                            fallback={<ChartSkeleton />}
                        >
                            {salesChart && <SalesChart points={salesChart} />}
                        </Deferred>
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
                        <div className="flex flex-col gap-1.5">
                            <CardTitle>Performa cabang</CardTitle>
                            <CardDescription>{monthLabel}</CardDescription>
                        </div>
                        {can('reports.branch.view') && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={reports.branches()}>
                                    Laporan lengkap
                                </Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        <Deferred
                            data="branchPerformance"
                            fallback={
                                <div className="flex flex-col gap-2">
                                    {Array.from({ length: 3 }, (_, index) => (
                                        <Skeleton
                                            key={index}
                                            className="h-10 w-full"
                                        />
                                    ))}
                                </div>
                            }
                        >
                            {branchPerformance && (
                                <BranchPerformanceTable
                                    rows={branchPerformance}
                                />
                            )}
                        </Deferred>
                    </CardContent>
                </Card>

                <div className="grid min-w-0 gap-6 lg:grid-cols-2">
                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Pesanan masuk</CardTitle>
                            <CardDescription>
                                Yang paling lama menunggu lebih dulu.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {incomingOrders.length === 0 ? (
                                <EmptyNote
                                    icon={Inbox}
                                    text="Tidak ada pesanan yang menunggu diproses."
                                />
                            ) : (
                                <ul className="flex flex-col divide-y rounded-lg border">
                                    {incomingOrders.map((order) => (
                                        <li
                                            key={order.id}
                                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={orderShow(order.id)}
                                                    className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                                                >
                                                    {order.order_number}
                                                </Link>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {order.customer_name} ·{' '}
                                                    {order.branch_name}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm tabular-nums">
                                                    {formatRupiah(
                                                        order.grand_total,
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatRelative(
                                                        order.paid_at,
                                                    )}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {can('orders.view') && (
                                <div>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={ordersIndex({
                                                query: { status: 'paid' },
                                            })}
                                        >
                                            Lihat semua pesanan masuk
                                        </Link>
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Stok menipis</CardTitle>
                            <CardDescription>
                                Kekurangan terbesar terhadap minimum lebih dulu.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {lowStock.length === 0 ? (
                                <EmptyNote
                                    icon={Boxes}
                                    text="Tidak ada stok di bawah minimum."
                                />
                            ) : (
                                <ul className="flex flex-col divide-y rounded-lg border">
                                    {lowStock.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {row.product_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    <span className="font-mono">
                                                        {row.sku}
                                                    </span>
                                                    {` · ${row.branch_name} / ${row.warehouse_name}`}
                                                </p>
                                            </div>
                                            <span className="text-sm tabular-nums">
                                                {formatNumber(row.quantity)}
                                                <span className="text-muted-foreground">
                                                    {' / '}
                                                    {formatNumber(
                                                        row.min_quantity,
                                                    )}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {can('reports.stock.view') && (
                                <div>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={reports.stock({
                                                query: { state: 'low' },
                                            })}
                                        >
                                            Laporan stok menipis
                                        </Link>
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function EmptyNote({ icon: Icon, text }: { icon: typeof Inbox; text: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center">
            <Icon aria-hidden className="size-5 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">{text}</p>
        </div>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
