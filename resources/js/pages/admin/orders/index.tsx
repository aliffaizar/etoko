import { Head, Link } from '@inertiajs/react';
import { Eye, Package } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import type { DataTableColumn, FilterField, FilterOption } from '@/types';
import {
    ORDER_STATUS_TONE,
    PAYMENT_STATUS_TONE,
    type PaginatedOrders,
} from '@/types/order';

type OrdersIndexProps = {
    orders: PaginatedOrders;
    filters: Record<string, string>;
    summary: {
        pending_payment: number;
        paid: number;
        processing: number;
        ready_for_pickup: number;
        shipped: number;
        open: number;
    };
    options: {
        statuses: FilterOption[];
        payment_statuses: FilterOption[];
        fulfillment_types: FilterOption[];
        branches: FilterOption[];
    };
    is_central: boolean;
};

export default function OrdersIndex({
    orders,
    summary,
    options,
    is_central: isCentral,
}: OrdersIndexProps) {
    const table = useTableQuery({
        only: ['orders', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari pesanan',
            placeholder: 'Cari nomor pesanan, nama, email, telepon…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-52',
        },
        {
            type: 'select',
            key: 'payment_status',
            label: 'Pembayaran',
            options: options.payment_statuses,
            allLabel: 'Semua pembayaran',
            className: 'sm:w-52',
        },
        {
            type: 'select',
            key: 'fulfillment_type',
            label: 'Pemenuhan',
            options: options.fulfillment_types,
            allLabel: 'Semua pemenuhan',
            className: 'sm:w-44',
        },
        // A branch account's listing can only ever contain its own branch, so
        // the select is left out rather than shown with one option.
        ...(isCentral
            ? [
                  {
                      type: 'select' as const,
                      key: 'branch',
                      label: 'Cabang',
                      options: options.branches,
                      allLabel: 'Semua cabang',
                      className: 'sm:w-48',
                  },
              ]
            : []),
    ];

    const columns: DataTableColumn<PaginatedOrders['data'][number]>[] = [
        {
            key: 'order_number',
            header: 'Pesanan',
            sortKey: 'order_number',
            cell: (order) => (
                <div className="min-w-0">
                    <Link
                        href={orderShow(order.id).url}
                        className="truncate font-medium tabular-nums hover:underline"
                    >
                        {order.order_number}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {formatDateTime(order.created_at)}
                    </p>
                    {/* Only on mobile, where the customer column (which
                        carries the branch) is hidden. */}
                    {order.branch && (
                        <p className="truncate text-xs text-muted-foreground sm:hidden">
                            {order.branch.name}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'customer_name',
            header: 'Pelanggan',
            // Hidden on narrow screens so the status — the column somebody
            // scanning a queue is actually looking for — is on screen without
            // scrolling the table sideways. The name is one tap away in the
            // detail, and the order number above already identifies the row.
            hideOnMobile: true,
            cell: (order) => (
                <div className="min-w-0">
                    <p className="truncate text-sm">{order.customer_name}</p>
                    {order.branch && (
                        <p className="truncate text-xs text-muted-foreground">
                            {order.branch.name}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (order) => (
                <StatusBadge
                    label={order.status_label}
                    tone={ORDER_STATUS_TONE[order.status]}
                />
            ),
        },
        {
            key: 'payment_status',
            header: 'Pembayaran',
            hideOnMobile: true,
            cell: (order) => (
                <StatusBadge
                    label={order.payment_status_label}
                    tone={PAYMENT_STATUS_TONE[order.payment_status]}
                />
            ),
        },
        {
            key: 'fulfillment_type',
            header: 'Pemenuhan',
            hideOnMobile: true,
            cell: (order) => (
                <span className="text-sm">{order.fulfillment_label}</span>
            ),
        },
        {
            key: 'item_count',
            header: 'Item',
            align: 'right',
            hideOnMobile: true,
            cell: (order) => (
                <span className="text-sm tabular-nums">
                    {order.item_count}
                </span>
            ),
        },
        {
            key: 'grand_total',
            header: 'Total',
            sortKey: 'grand_total',
            align: 'right',
            cell: (order) => (
                <span className="text-sm font-medium tabular-nums">
                    {formatRupiah(order.grand_total)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (order) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={orderShow(order.id).url}>
                        <Eye aria-hidden />
                        Detail
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Pesanan" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Pesanan"
                    description="Antrean pesanan yang harus diproses, beserta riwayatnya."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Menunggu pembayaran"
                        value={summary.pending_payment}
                        hint="Stoknya sedang ditahan"
                    />
                    <StatCard
                        label="Sudah dibayar"
                        value={summary.paid}
                        hint="Siap mulai diproses"
                    />
                    <StatCard
                        label="Sedang diproses"
                        value={summary.processing + summary.ready_for_pickup}
                        hint="Termasuk yang siap diambil"
                    />
                    <StatCard
                        label="Belum selesai"
                        value={summary.open}
                        hint="Seluruh pesanan yang masih berjalan"
                    />
                </div>

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                />

                <DataTable
                    columns={columns}
                    rows={orders.data}
                    rowKey={(order) => order.id}
                    paginator={orders}
                    rowLabel="pesanan"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Package,
                        title: table.isFiltered
                            ? 'Tidak ada pesanan yang cocok'
                            : 'Belum ada pesanan',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat pesanan lain.'
                            : 'Pesanan dari toko akan muncul di sini.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>
        </>
    );
}
