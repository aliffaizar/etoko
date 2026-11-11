import { Head, Link } from '@inertiajs/react';
import { Eye, Undo2 } from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateTime } from '@/lib/format';
import { show as orderShow } from '@/routes/admin/orders';
import { show as returnShow } from '@/routes/admin/returns';
import type { DataTableColumn, FilterField, FilterOption } from '@/types';
import { RETURN_STATE_TONE, type PaginatedReturns } from '@/types/return';

type ReturnsIndexProps = {
    returns: PaginatedReturns;
    filters: Record<string, string>;
    summary: {
        requested: number;
        approved: number;
        awaiting_refund: number;
        completed: number;
        refused: number;
    };
    options: {
        statuses: FilterOption[];
        types: FilterOption[];
        reasons: FilterOption[];
        branches: FilterOption[];
    };
    is_central: boolean;
};

export default function ReturnsIndex({
    returns,
    summary,
    options,
    is_central: isCentral,
}: ReturnsIndexProps) {
    const table = useTableQuery({
        only: ['returns', 'filters'],
        defaultSort: 'requested_at',
        defaultDirection: 'desc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari retur',
            placeholder: 'Cari nomor retur, nomor pesanan, nama pelanggan…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-48',
        },
        // The one filter the queue exists for: requests that still await a
        // decision or an action. A plain status filter cannot express it,
        // because three of the six statuses count as open.
        {
            type: 'select',
            key: 'open',
            label: 'Perlu ditindak',
            options: [{ value: '1', label: 'Belum selesai' }],
            allLabel: 'Semua',
            className: 'sm:w-44',
        },
        {
            type: 'select',
            key: 'reason',
            label: 'Alasan',
            options: options.reasons,
            allLabel: 'Semua alasan',
            className: 'sm:w-52',
        },
        {
            type: 'select',
            key: 'type',
            label: 'Jenis',
            options: options.types,
            allLabel: 'Semua jenis',
            className: 'sm:w-44',
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

    const columns: DataTableColumn<PaginatedReturns['data'][number]>[] = [
        {
            key: 'code',
            header: 'Retur',
            sortKey: 'code',
            cell: (item) => (
                <div className="min-w-0">
                    <Link
                        href={returnShow(item.id).url}
                        className="truncate font-medium tabular-nums hover:underline"
                    >
                        {item.code}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {item.order?.customer_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground sm:hidden">
                        {item.reason_label}
                    </p>
                </div>
            ),
        },
        {
            key: 'order',
            header: 'Pesanan',
            hideOnMobile: true,
            cell: (item) => (
                <div className="min-w-0">
                    {item.order ? (
                        <Link
                            href={orderShow(item.order.id).url}
                            className="truncate text-sm tabular-nums hover:underline"
                        >
                            {item.order.order_number}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                    <p className="truncate text-xs text-muted-foreground">
                        {item.branch?.name ?? item.type_label}
                    </p>
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Alasan',
            hideOnMobile: true,
            cell: (item) => (
                <div className="min-w-0">
                    <p className="truncate text-sm">{item.reason_label}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {item.type_label}
                        {item.photo_count > 0 && ` · ${item.photo_count} foto`}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (item) => (
                <StatusBadge
                    label={item.status_label}
                    tone={RETURN_STATE_TONE[item.status]}
                />
            ),
        },
        {
            key: 'requested_at',
            header: 'Diajukan',
            sortKey: 'requested_at',
            hideOnMobile: true,
            cell: (item) => (
                <span className="text-sm text-muted-foreground">
                    {formatDateTime(item.requested_at)}
                </span>
            ),
        },
        {
            key: 'item_count',
            header: 'Unit',
            align: 'right',
            cell: (item) => (
                <span className="text-sm font-medium tabular-nums">
                    {item.item_count}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (item) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={returnShow(item.id).url}>
                        <Eye aria-hidden />
                        Detail
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Retur" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Retur"
                    description="Pengajuan retur pelanggan: persetujuan, penerimaan barang, dan pengembalian ke stok."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Menunggu persetujuan"
                        value={summary.requested}
                        hint="Perlu disetujui atau ditolak"
                    />
                    <StatCard
                        label="Menunggu barang"
                        value={summary.approved}
                        hint="Sudah disetujui, barang belum tiba"
                    />
                    <StatCard
                        label="Menunggu refund"
                        value={summary.awaiting_refund}
                        hint="Barang sudah diterima di cabang"
                    />
                    <StatCard
                        label="Selesai"
                        value={summary.completed}
                        hint={`${summary.refused} ditolak atau dibatalkan`}
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
                    rows={returns.data}
                    rowKey={(item) => item.id}
                    paginator={returns}
                    rowLabel="retur"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: Undo2,
                        title: table.isFiltered
                            ? 'Tidak ada retur yang cocok'
                            : 'Belum ada pengajuan retur',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat retur lain.'
                            : 'Pengajuan retur dari pelanggan akan muncul di sini.',
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
