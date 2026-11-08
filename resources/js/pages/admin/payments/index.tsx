import { Head, Link } from '@inertiajs/react';
import { Eye, Wallet } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import { show as paymentShow } from '@/routes/admin/payments';
import type { DataTableColumn, FilterField, FilterOption } from '@/types';
import { PAYMENT_STATE_TONE, type PaginatedPayments } from '@/types/payment';

type PaymentsIndexProps = {
    payments: PaginatedPayments;
    filters: Record<string, string>;
    summary: {
        awaiting_verification: number;
        open: number;
        overrun: number;
        success: number;
        failed: number;
    };
    options: {
        statuses: FilterOption[];
        method_types: FilterOption[];
        branches: FilterOption[];
    };
    is_central: boolean;
};

export default function PaymentsIndex({
    payments,
    summary,
    options,
    is_central: isCentral,
}: PaymentsIndexProps) {
    const table = useTableQuery({
        only: ['payments', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari pembayaran',
            placeholder: 'Cari nomor pesanan, referensi, nama pelanggan…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-48',
        },
        {
            type: 'select',
            key: 'method_type',
            label: 'Jenis metode',
            options: options.method_types,
            allLabel: 'Semua metode',
            className: 'sm:w-52',
        },
        // The one filter the queue exists for: open attempts with a receipt
        // waiting to be looked at. A plain status filter cannot express it,
        // because "pending" also covers customers who have not paid yet.
        {
            type: 'select',
            key: 'awaiting_proof',
            label: 'Bukti transfer',
            options: [{ value: '1', label: 'Menunggu diperiksa' }],
            allLabel: 'Semua',
            className: 'sm:w-48',
        },
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

    const columns: DataTableColumn<PaginatedPayments['data'][number]>[] = [
        {
            key: 'order',
            header: 'Pesanan',
            cell: (payment) => (
                <div className="min-w-0">
                    {payment.order ? (
                        <Link
                            href={orderShow(payment.order.id).url}
                            className="truncate font-medium tabular-nums hover:underline"
                        >
                            {payment.order.order_number}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                    <p className="truncate text-xs text-muted-foreground">
                        {payment.order?.customer_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground sm:hidden">
                        {payment.method_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'method_name',
            header: 'Metode',
            hideOnMobile: true,
            cell: (payment) => (
                <div className="min-w-0">
                    <p className="truncate text-sm">{payment.method_name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {payment.branch?.name ?? payment.method_type_label}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (payment) => (
                <div className="flex flex-col items-start gap-1">
                    <StatusBadge
                        label={payment.status_label}
                        tone={PAYMENT_STATE_TONE[payment.status]}
                    />
                    {payment.has_proof && payment.status === 'pending' && (
                        <span className="text-xs text-amber-700 dark:text-amber-300">
                            Bukti menunggu diperiksa
                        </span>
                    )}
                    {payment.has_overrun && (
                        <span className="text-xs text-destructive">
                            Batas waktu terlewat
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'expires_at',
            header: 'Batas waktu',
            sortKey: 'expires_at',
            hideOnMobile: true,
            cell: (payment) => (
                <span className="text-sm text-muted-foreground">
                    {payment.expires_at
                        ? formatDateTime(payment.expires_at)
                        : 'Tanpa batas'}
                </span>
            ),
        },
        {
            key: 'created_at',
            header: 'Dibuat',
            sortKey: 'created_at',
            hideOnMobile: true,
            cell: (payment) => (
                <span className="text-sm text-muted-foreground">
                    {formatDateTime(payment.created_at)}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Nominal',
            sortKey: 'amount',
            align: 'right',
            cell: (payment) => (
                <span className="text-sm font-medium tabular-nums">
                    {formatRupiah(payment.amount)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (payment) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={paymentShow(payment.id).url}>
                        <Eye aria-hidden />
                        Detail
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Pembayaran" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Pembayaran"
                    description="Bukti transfer yang menunggu diperiksa, beserta riwayat pembayaran pesanan."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Bukti menunggu diperiksa"
                        value={summary.awaiting_verification}
                        hint="Perlu dikonfirmasi atau ditolak"
                    />
                    <StatCard
                        label="Menunggu pembayaran"
                        value={summary.open}
                        hint="Termasuk yang belum ada buktinya"
                    />
                    <StatCard
                        label="Lewat batas waktu"
                        value={summary.overrun}
                        hint="Akan ditutup penyapu otomatis"
                    />
                    <StatCard
                        label="Lunas"
                        value={summary.success}
                        hint={`${summary.failed} gagal atau kedaluwarsa`}
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
                    rows={payments.data}
                    rowKey={(payment) => payment.id}
                    paginator={payments}
                    rowLabel="pembayaran"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Wallet,
                        title: table.isFiltered
                            ? 'Tidak ada pembayaran yang cocok'
                            : 'Belum ada pembayaran',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat pembayaran lain.'
                            : 'Pembayaran pesanan dari toko akan muncul di sini.',
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
