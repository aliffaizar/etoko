import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Bell,
    BellOff,
    Boxes,
    FileClock,
    Inbox,
    PackageCheck,
    TrendingUp,
    TriangleAlert,
    Users,
    Warehouse,
} from 'lucide-react';
import Heading from '@/components/heading';
import { FilterBar } from '@/components/panel/filter-bar';
import { ChartSkeleton, SalesChart } from '@/components/panel/sales-chart';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useAuthorization } from '@/hooks/use-authorization';
import { formatNumber, formatRelative, formatRupiah } from '@/lib/format';
import reports from '@/routes/admin/reports';
import { dashboard } from '@/routes/branch';
import notifications from '@/routes/branch/notifications';
import stocks from '@/routes/branch/stocks';
import stockTransfers from '@/routes/branch/stock-transfers';
import type {
    BranchAlert,
    FilterOption,
    LowStockRow,
    ReportFilters,
    SalesPoint,
} from '@/types';

type DashboardProps = {
    stats: {
        warehouses: number;
        staff: number;
        low_stock: number;
        open_drafts: number;
    };
    stock: {
        units: number;
        variants: number;
        in_month: number;
        out_month: number;
    };
    /** Null when the viewer may not read sales (`reports.sales.view`). */
    sales: { net: number; orders: number; average_order: number } | null;
    /** Null when the viewer does not handle orders. */
    orders: { incoming: number; processing: number } | null;
    lowStock: LowStockRow[];
    notifications: BranchAlert[];
    /** Deferred; null when the viewer may not read sales. */
    salesChart?: SalesPoint[] | null;
    period: ReportFilters;
    branchFilter: { selected: string; options: FilterOption[] };
};

/**
 * The branch dashboard (P1-E4-T9).
 *
 * The low-stock panel is read from `stocks`, so it shows the situation now.
 * The alert feed beside it is read from the notifications, so it shows what
 * was true when each alert fired — which is what makes "why was I told this?"
 * answerable. The two are allowed to disagree, and when they do the panel is
 * the one to act on.
 */
export default function BranchDashboard({
    stats,
    stock,
    sales,
    orders,
    lowStock,
    notifications: alerts,
    salesChart,
    branchFilter,
}: DashboardProps) {
    const { auth, can } = useAuthorization();
    const unreadCount = alerts.filter((alert) => !alert.is_read).length;
    const supervisedBranch = branchFilter.options.find(
        (option) => option.value === branchFilter.selected,
    );

    return (
        <>
            <Head title="Dashboard Cabang" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Dashboard Cabang"
                    description={
                        auth?.branch
                            ? `Operasional ${auth.branch.name}.`
                            : supervisedBranch
                              ? `Operasional ${supervisedBranch.label}.`
                              : 'Operasional seluruh cabang.'
                    }
                />

                {/* Central staff supervise any one branch, or all of them. */}
                {branchFilter.options.length > 0 && (
                    <FilterBar
                        fields={[
                            {
                                type: 'select',
                                key: 'branch',
                                label: 'Cabang',
                                allLabel: 'Semua cabang',
                                options: branchFilter.options,
                            },
                        ]}
                        values={{ branch: branchFilter.selected }}
                        onChange={(next) =>
                            router.get(
                                dashboard.url(),
                                next.branch ? { branch: next.branch } : {},
                                { preserveScroll: true },
                            )
                        }
                        onReset={() => router.get(dashboard.url())}
                        isFiltered={branchFilter.selected !== ''}
                    />
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {sales && (
                        <StatCard
                            label="Penjualan bulan ini"
                            value={formatRupiah(sales.net)}
                            hint={`${formatNumber(sales.orders)} pesanan · rata-rata ${formatRupiah(sales.average_order)}`}
                            icon={TrendingUp}
                        />
                    )}
                    {orders && (
                        <>
                            <StatCard
                                label="Pesanan masuk"
                                value={orders.incoming}
                                hint="Sudah dibayar, belum mulai dikemas."
                                icon={Inbox}
                            />
                            <StatCard
                                label="Pesanan diproses"
                                value={orders.processing}
                                hint="Sedang dikemas, belum dikirim/diambil."
                                icon={PackageCheck}
                            />
                        </>
                    )}
                    <StatCard
                        label="Stok produk"
                        value={stock.units}
                        hint={`${formatNumber(stock.variants)} varian ada di rak`}
                        icon={Boxes}
                    />
                    <StatCard
                        label="Barang masuk bulan ini"
                        value={stock.in_month}
                        hint="Unit, dari riwayat stok."
                        icon={ArrowDownToLine}
                    />
                    <StatCard
                        label="Barang keluar bulan ini"
                        value={stock.out_month}
                        hint="Unit, termasuk yang terjual."
                        icon={ArrowUpFromLine}
                    />
                </div>

                {salesChart !== null && (
                    <Card className="min-w-0">
                        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
                            <div className="flex flex-col gap-1.5">
                                <CardTitle>Penjualan cabang</CardTitle>
                                <CardDescription>
                                    Penjualan bersih harian, 30 hari terakhir.
                                </CardDescription>
                            </div>
                            {can('reports.sales.view') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={reports.sales({
                                            query: branchFilter.selected
                                                ? {
                                                      branch: branchFilter.selected,
                                                  }
                                                : {},
                                        })}
                                    >
                                        Laporan penjualan
                                    </Link>
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            <Deferred
                                data="salesChart"
                                fallback={<ChartSkeleton />}
                            >
                                {salesChart && (
                                    <SalesChart points={salesChart} />
                                )}
                            </Deferred>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Di bawah minimum"
                        value={stats.low_stock}
                        icon={TriangleAlert}
                        hint="Varian yang perlu dipesan ulang."
                    />
                    <StatCard
                        label="Dokumen draf"
                        value={stats.open_drafts}
                        icon={FileClock}
                        hint="Barang masuk & keluar yang belum diposting."
                    />
                    <StatCard
                        label="Gudang"
                        value={stats.warehouses}
                        icon={Warehouse}
                    />
                    <StatCard
                        label="Staf Cabang"
                        value={stats.staff}
                        icon={Users}
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Stok di bawah minimum</CardTitle>
                            <CardDescription>
                                Diurutkan dari kekurangan terbesar terhadap
                                minimumnya, bukan dari jumlah terkecil.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {lowStock.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center">
                                    <Boxes
                                        aria-hidden
                                        className="size-5 text-muted-foreground"
                                    />
                                    <p className="text-sm font-medium">
                                        Tidak ada stok di bawah minimum
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Atur stok minimum di layar Stok supaya
                                        peringatan muncul di sini.
                                    </p>
                                </div>
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
                                                    {` · ${row.warehouse_name}`}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <span className="text-sm tabular-nums">
                                                    {formatNumber(row.quantity)}
                                                    <span className="text-muted-foreground">
                                                        {' / '}
                                                        {formatNumber(
                                                            row.min_quantity,
                                                        )}
                                                    </span>
                                                </span>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={stocks.movements.url(
                                                            [
                                                                row.product_variant_id,
                                                            ],
                                                            {
                                                                query: {
                                                                    warehouse:
                                                                        String(
                                                                            row.warehouse_id,
                                                                        ),
                                                                },
                                                            },
                                                        )}
                                                    >
                                                        Riwayat
                                                    </Link>
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={stocks.index({
                                            query: { state: 'low' },
                                        })}
                                    >
                                        Lihat semua stok rendah
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center gap-2">
                                Notifikasi
                                {unreadCount > 0 && (
                                    <StatusBadge
                                        label={`${unreadCount} baru`}
                                        tone="warning"
                                    />
                                )}
                            </CardTitle>
                            <CardDescription>
                                Stok rendah dan langkah transfer stok. Angka di
                                sini adalah angka saat notifikasi dikirim, bukan
                                angka sekarang.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {alerts.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center">
                                    <BellOff
                                        aria-hidden
                                        className="size-5 text-muted-foreground"
                                    />
                                    <p className="text-sm font-medium">
                                        Belum ada notifikasi
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Notifikasi dikirim saat stok melewati
                                        ambang minimumnya, atau saat transfer
                                        stok menunggu Anda.
                                    </p>
                                </div>
                            ) : (
                                <ul className="flex flex-col divide-y rounded-lg border">
                                    {alerts.map((alert) => (
                                        <li
                                            key={alert.id}
                                            className={
                                                alert.is_read
                                                    ? 'flex items-start gap-3 p-3'
                                                    : 'flex items-start gap-3 bg-muted/40 p-3'
                                            }
                                        >
                                            <Bell
                                                aria-hidden
                                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            />
                                            <div className="min-w-0">
                                                <p className="text-sm">
                                                    {alert.type ===
                                                    'stock_transfer' ? (
                                                        <Link
                                                            href={stockTransfers.show(
                                                                alert.stock_transfer_id,
                                                            )}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {alert.message}
                                                        </Link>
                                                    ) : (
                                                        alert.message
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatRelative(
                                                        alert.created_at,
                                                    )}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {unreadCount > 0 && (
                                <div>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                notifications.read().url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Tandai sudah dibaca
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

BranchDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
