import { Head, router, setLayoutProps } from '@inertiajs/react';
import {
    Building2,
    ChevronLeft,
    ChevronRight,
    MapPin,
    PackageCheck,
    PackageOpen,
    Store,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import PackingController from '@/actions/App/Http/Controllers/Branch/PackingController';
import Heading from '@/components/heading';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatNumber } from '@/lib/format';
import packing from '@/routes/branch/packing';
import type {
    FilterField,
    PackingOrder,
    Paginated,
    StockBranch,
    StockScopeOption,
} from '@/types';

type Stage = 'to_pack' | 'packing';

type PackingProps = {
    branch: StockBranch;
    branches: StockScopeOption[];
    may_choose_branch: boolean;
    orders: Paginated<PackingOrder>;
    counts: { to_pack: number; packing: number };
    filters: { stage: Stage; search: string };
};

const FILTER_FIELDS: FilterField[] = [
    {
        type: 'search',
        key: 'search',
        label: 'Cari pesanan',
        placeholder: 'Cari nomor pesanan atau nama pelanggan…',
    },
];

/** Query keys that name a place or a tab rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'stage'];

/**
 * The warehouse's part of an order (P3-E6-T4).
 *
 * Paid orders, oldest payment first, each split into what to take from which
 * warehouse and shelf. Where the units come from was decided at checkout and
 * is read back from the order's stock holds, so this list and the stock
 * ledger always name the same shelf (ADR-033).
 *
 * "Mulai kemas" is the only action: it moves the order to "Diproses" so the
 * branch admin knows it is in hand. Shipping, handing over and cancelling stay
 * on the order screen.
 */
export default function PackingIndex({
    branch,
    branches,
    may_choose_branch,
    orders,
    counts,
    filters,
}: PackingProps) {
    setLayoutProps({
        breadcrumbs: [{ title: 'Daftar Kemas', href: packing.index() }],
    });

    const table = useTableQuery({
        only: ['orders', 'counts', 'filters', 'branch'],
        scopeKeys: SCOPE_KEYS,
    });

    return (
        <>
            <Head title={`Daftar Kemas · ${branch.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Daftar Kemas"
                    description="Pesanan lunas yang perlu disiapkan gudang, lengkap dengan gudang dan rak tempat barangnya diambil."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={filters.stage}
                        onValueChange={(next) =>
                            next !== '' &&
                            table.setFilters({ stage: next, page: null })
                        }
                        aria-label="Tahap pengemasan"
                    >
                        <ToggleGroupItem value="to_pack" className="px-3">
                            Perlu dikemas
                            <span className="ml-1.5 text-muted-foreground tabular-nums">
                                {formatNumber(counts.to_pack)}
                            </span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="packing" className="px-3">
                            Sedang dikemas
                            <span className="ml-1.5 text-muted-foreground tabular-nums">
                                {formatNumber(counts.packing)}
                            </span>
                        </ToggleGroupItem>
                    </ToggleGroup>

                    {may_choose_branch ? (
                        <div className="grid gap-1.5 sm:w-64">
                            <Label
                                htmlFor="packing-branch"
                                className="text-xs text-muted-foreground"
                            >
                                Cabang
                            </Label>
                            <Select
                                value={String(branch.id)}
                                onValueChange={(next) =>
                                    table.setFilters({
                                        branch: next,
                                        page: null,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="packing-branch"
                                    aria-label="Cabang"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {branches.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>
                    ) : (
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <Building2
                                aria-hidden
                                className="size-4 text-muted-foreground"
                            />
                            {branch.name}
                        </p>
                    )}
                </div>

                <FilterBar
                    fields={FILTER_FIELDS}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                />

                {orders.data.length === 0 ? (
                    <EmptyState
                        stage={filters.stage}
                        isFiltered={table.isFiltered}
                    />
                ) : (
                    <div
                        className="grid gap-4 lg:grid-cols-2"
                        aria-busy={table.isLoading}
                    >
                        {orders.data.map((order) => (
                            <PackingCard key={order.id} order={order} />
                        ))}
                    </div>
                )}

                {orders.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between gap-3 text-sm"
                        aria-label="Halaman"
                    >
                        <p className="text-muted-foreground">
                            {formatNumber(orders.from ?? 0)}–
                            {formatNumber(orders.to ?? 0)} dari{' '}
                            {formatNumber(orders.total)} pesanan
                        </p>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={orders.current_page <= 1}
                                onClick={() =>
                                    table.goToPage(orders.current_page - 1)
                                }
                            >
                                <ChevronLeft aria-hidden />
                                Sebelumnya
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    orders.current_page >= orders.last_page
                                }
                                onClick={() =>
                                    table.goToPage(orders.current_page + 1)
                                }
                            >
                                Berikutnya
                                <ChevronRight aria-hidden />
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}

function PackingCard({ order }: { order: PackingOrder }) {
    const [isStarting, setIsStarting] = useState(false);
    const isWaiting = order.status === 'paid';
    const FulfillmentIcon = order.fulfillment_type === 'pickup' ? Store : Truck;

    const start = () => {
        router.post(
            PackingController.start.url(order.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setIsStarting(true),
                onFinish: () => setIsStarting(false),
            },
        );
    };

    return (
        <Card className="gap-4">
            <CardHeader className="gap-2">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <CardTitle className="font-mono text-base">
                            {order.order_number}
                        </CardTitle>
                        <CardDescription className="truncate">
                            {order.customer_name} ·{' '}
                            {formatNumber(order.unit_count)} unit
                        </CardDescription>
                    </div>
                    <StatusBadge
                        label={order.status_label}
                        tone={isWaiting ? 'warning' : 'info'}
                    />
                </div>
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                        <FulfillmentIcon aria-hidden className="size-3.5" />
                        {order.fulfillment_label}
                    </span>
                    <span>
                        {isWaiting
                            ? `Lunas ${formatDateTime(order.paid_at)}`
                            : `Mulai dikemas ${formatDateTime(order.processed_at)}`}
                    </span>
                </p>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {order.picks.map((pick) => (
                    <section key={pick.warehouse} className="grid gap-2">
                        <h3 className="flex items-center gap-1.5 text-sm font-medium">
                            <PackageOpen
                                aria-hidden
                                className="size-4 text-muted-foreground"
                            />
                            {pick.warehouse}
                        </h3>
                        <ul className="divide-y rounded-md border text-sm">
                            {pick.lines.map((line) => (
                                <li
                                    key={`${pick.warehouse}-${line.sku}`}
                                    className="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {line.product_name}
                                            {line.variant_name
                                                ? ` · ${line.variant_name}`
                                                : ''}
                                        </p>
                                        <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <span className="font-mono">
                                                {line.sku}
                                            </span>
                                            {line.shelf_location && (
                                                <span className="flex items-center gap-0.5">
                                                    <MapPin
                                                        aria-hidden
                                                        className="size-3"
                                                    />
                                                    rak {line.shelf_location}
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <span className="shrink-0 font-medium tabular-nums">
                                        × {formatNumber(line.quantity)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                {order.customer_note && (
                    <p className="rounded-md bg-muted/60 px-3 py-2 text-xs">
                        <span className="font-medium">Catatan pelanggan:</span>{' '}
                        {order.customer_note}
                    </p>
                )}

                {isWaiting && (
                    <Button
                        onClick={start}
                        disabled={isStarting}
                        className="self-end"
                    >
                        <PackageCheck aria-hidden />
                        Mulai kemas
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function EmptyState({
    stage,
    isFiltered,
}: {
    stage: Stage;
    isFiltered: boolean;
}) {
    const title = isFiltered
        ? 'Tidak ada pesanan yang cocok'
        : stage === 'to_pack'
          ? 'Tidak ada pesanan yang menunggu dikemas'
          : 'Tidak ada pesanan yang sedang dikemas';

    return (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-12 text-center">
            <PackageCheck
                aria-hidden
                className="size-8 text-muted-foreground"
            />
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {isFiltered
                    ? 'Ubah atau hapus pencarian untuk melihat pesanan lain.'
                    : 'Pesanan baru muncul di sini begitu pembayarannya lunas.'}
            </p>
        </div>
    );
}
