import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import { show as returnShow } from '@/routes/admin/returns';
import type { DataTableColumn, FilterField, FilterOption } from '@/types';
import { REFUND_STATE_TONE, type PaginatedRefunds } from '@/types/return';

type RefundsIndexProps = {
    refunds: PaginatedRefunds;
    filters: Record<string, string>;
    summary: {
        pending: number;
        pending_amount: number;
        processed: number;
        processed_amount: number;
        failed: number;
    };
    options: {
        statuses: FilterOption[];
        methods: FilterOption[];
        branches: FilterOption[];
    };
    is_central: boolean;
};

/**
 * Money going back, as a worklist (P2-E4-T4).
 *
 * The listing answers what the return queue cannot: **which refunds has
 * somebody promised and not yet sent?** A bank transfer is opened pending and
 * settled once the money has actually moved, so `pending_amount` at the top is
 * the shop's outstanding liability to its customers.
 *
 * The buttons that settle or fail a refund live on the return and order detail
 * screens rather than here, because deciding one needs the order beside it.
 */
export default function RefundsIndex({
    refunds,
    summary,
    options,
    is_central: isCentral,
}: RefundsIndexProps) {
    const table = useTableQuery({
        only: ['refunds', 'filters'],
        defaultSort: 'created_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari refund',
            placeholder: 'Cari nomor refund, referensi, nomor pesanan…',
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
            key: 'method',
            label: 'Cara',
            options: options.methods,
            allLabel: 'Semua cara',
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

    const columns: DataTableColumn<PaginatedRefunds['data'][number]>[] = [
        {
            key: 'code',
            header: 'Refund',
            cell: (refund) => (
                <div className="min-w-0">
                    <p className="truncate font-medium tabular-nums">
                        {refund.code}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {refund.order?.customer_name}
                    </p>
                    {refund.reference && (
                        <p className="truncate font-mono text-xs text-muted-foreground">
                            {refund.reference}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'order',
            header: 'Pesanan',
            hideOnMobile: true,
            cell: (refund) => (
                <div className="min-w-0">
                    {refund.order ? (
                        <Link
                            href={orderShow(refund.order.id).url}
                            className="truncate text-sm tabular-nums hover:underline"
                        >
                            {refund.order.order_number}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                    {refund.return_id && refund.return_code ? (
                        <Link
                            href={returnShow(refund.return_id).url}
                            className="block truncate text-xs text-muted-foreground hover:underline"
                        >
                            {refund.return_code}
                        </Link>
                    ) : (
                        <p className="truncate text-xs text-muted-foreground">
                            Tanpa retur
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'method',
            header: 'Cara',
            hideOnMobile: true,
            cell: (refund) => (
                <div className="min-w-0">
                    <p className="truncate text-sm">{refund.method_label}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {refund.branch?.name ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (refund) => (
                <div className="flex flex-col items-start gap-1">
                    <StatusBadge
                        label={refund.status_label}
                        tone={REFUND_STATE_TONE[refund.status]}
                    />
                    {refund.status === 'pending' && (
                        <span className="text-xs text-amber-700 dark:text-amber-300">
                            Belum ditransfer
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'created_at',
            header: 'Dibuat',
            sortKey: 'created_at',
            hideOnMobile: true,
            cell: (refund) => (
                <span className="text-sm text-muted-foreground">
                    {formatDateTime(refund.created_at)}
                </span>
            ),
        },
        {
            key: 'processed_at',
            header: 'Diproses',
            sortKey: 'processed_at',
            hideOnMobile: true,
            cell: (refund) => (
                <span className="text-sm text-muted-foreground">
                    {refund.processed_at
                        ? formatDateTime(refund.processed_at)
                        : 'Belum'}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Nominal',
            sortKey: 'amount',
            align: 'right',
            cell: (refund) => (
                <span className="text-sm font-medium tabular-nums">
                    {formatRupiah(refund.amount)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Refund" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Refund"
                    description="Pengembalian dana ke pelanggan: yang masih harus ditransfer dan yang sudah dikirim."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <StatCard
                        label="Menunggu diproses"
                        value={summary.pending}
                        hint={`${formatRupiah(summary.pending_amount)} belum ditransfer`}
                    />
                    <StatCard
                        label="Sudah dikembalikan"
                        value={summary.processed}
                        hint={formatRupiah(summary.processed_amount)}
                    />
                    <StatCard
                        label="Gagal"
                        value={summary.failed}
                        hint="Nominalnya kembali ke sisa dana pesanan"
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
                    rows={refunds.data}
                    rowKey={(refund) => refund.id}
                    paginator={refunds}
                    rowLabel="refund"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Banknote,
                        title: table.isFiltered
                            ? 'Tidak ada refund yang cocok'
                            : 'Belum ada pengembalian dana',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat refund lain.'
                            : 'Pengembalian dana dibuat dari halaman retur atau halaman pesanan.',
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
