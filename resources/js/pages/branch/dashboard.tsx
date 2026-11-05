import { Head, Link, router } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    Boxes,
    FileClock,
    TriangleAlert,
    Users,
    Warehouse,
} from 'lucide-react';
import Heading from '@/components/heading';
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
import { formatNumber, formatRelative } from '@/lib/format';
import { dashboard } from '@/routes/branch';
import notifications from '@/routes/branch/notifications';
import stocks from '@/routes/branch/stocks';
import type { LowStockAlert, LowStockRow } from '@/types';

type DashboardProps = {
    stats: {
        warehouses: number;
        staff: number;
        low_stock: number;
        open_drafts: number;
    };
    lowStock: LowStockRow[];
    notifications: LowStockAlert[];
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
    lowStock,
    notifications: alerts,
}: DashboardProps) {
    const { auth } = useAuthorization();
    const unreadCount = alerts.filter((alert) => !alert.is_read).length;

    return (
        <>
            <Head title="Dashboard Cabang" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Dashboard Cabang"
                    description={
                        auth?.branch
                            ? `Operasional ${auth.branch.name}.`
                            : 'Operasional cabang.'
                    }
                />

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
                                Peringatan stok
                                {unreadCount > 0 && (
                                    <StatusBadge
                                        label={`${unreadCount} baru`}
                                        tone="warning"
                                    />
                                )}
                            </CardTitle>
                            <CardDescription>
                                Angka di sini adalah angka saat peringatan
                                dikirim, bukan angka sekarang.
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
                                        Belum ada peringatan
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Peringatan dikirim saat stok melewati
                                        ambang minimumnya.
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
                                                    {alert.message}
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

                <p className="text-sm text-muted-foreground">
                    Penjualan cabang dan pesanan masuk ditambahkan pada epic
                    P1-E7 dan P2-E7.
                </p>
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
