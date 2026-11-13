import { Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRightLeft,
    ArrowUpRight,
    Plus,
} from 'lucide-react';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatNumber } from '@/lib/format';
import { TRANSFER_STATUS_TONE } from '@/lib/stock-transfer-status';
import stockTransfers from '@/routes/branch/stock-transfers';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StockScopeOption,
    StockTransferBranch,
    StockTransferRow,
    StockTransferSummary,
} from '@/types';

type StockTransfersProps = {
    transfers: Paginated<StockTransferRow>;
    summary: StockTransferSummary;
    filters: Record<string, string>;
    /** The branch the screen stands in; null for central looking at all. */
    perspective: StockTransferBranch | null;
    options: { statuses: StockScopeOption[]; branches: StockScopeOption[] };
    may_choose_branch: boolean;
    can: { create: boolean };
};

const DIRECTION_OPTIONS: StockScopeOption[] = [
    { value: 'incoming', label: 'Masuk ke cabang ini' },
    { value: 'outgoing', label: 'Keluar dari cabang ini' },
];

/**
 * Stock moving between branches (P2-E3-T3).
 *
 * Seen from one branch, every row is either coming in or going out, and the
 * stat cards say what is waiting on that branch: requests to decide, approved
 * goods to pack, parcels to unload. Central staff see every branch at once, or
 * stand in one with the branch filter.
 */
export default function StockTransfers({
    transfers,
    summary,
    perspective,
    options,
    may_choose_branch,
    can,
}: StockTransfersProps) {
    setLayoutProps({
        breadcrumbs: [{ title: 'Transfer Stok', href: stockTransfers.index() }],
    });

    const table = useTableQuery({
        only: ['transfers', 'filters', 'summary', 'perspective'],
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari transfer',
            placeholder: 'Cari kode transfer…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-52',
        },
    ];

    if (may_choose_branch) {
        filterFields.push({
            type: 'select',
            key: 'branch',
            label: 'Cabang',
            options: options.branches,
            allLabel: 'Semua cabang',
            className: 'sm:w-52',
        });
    }

    if (perspective !== null) {
        filterFields.push({
            type: 'select',
            key: 'direction',
            label: 'Arah',
            options: DIRECTION_OPTIONS,
            allLabel: 'Masuk & keluar',
            className: 'sm:w-52',
        });
    }

    const columns: DataTableColumn<StockTransferRow>[] = [
        {
            key: 'code',
            header: 'Transfer',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={stockTransfers.show(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.code}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {formatDateShort(row.requested_at)}
                        {row.requested_by ? ` · ${row.requested_by}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'route',
            header: 'Rute',
            cell: (row) => (
                <div className="flex min-w-0 items-center gap-2 text-sm">
                    {row.direction === 'incoming' && (
                        <ArrowDownLeft
                            aria-label="Masuk"
                            className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                    )}
                    {row.direction === 'outgoing' && (
                        <ArrowUpRight
                            aria-label="Keluar"
                            className="size-4 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                    )}
                    <span className="truncate">
                        {row.from_branch.name}
                        <span className="text-muted-foreground"> → </span>
                        {row.to_branch.name}
                    </span>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    label={row.status_label}
                    tone={TRANSFER_STATUS_TONE[row.status] ?? 'neutral'}
                />
            ),
        },
        {
            key: 'total_requested',
            header: 'Diminta',
            align: 'right',
            cell: (row) => (
                <div className="text-sm">
                    <p className="font-medium tabular-nums">
                        {formatNumber(row.total_requested)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {row.item_count} baris
                    </p>
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) => (
                <Button variant="outline" size="sm" asChild>
                    <Link href={stockTransfers.show(row.id)}>Lihat</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Transfer Stok" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Transfer Stok"
                    description={
                        perspective
                            ? `Permintaan stok antar cabang, dilihat dari ${perspective.name}. Stok baru berpindah saat barang dikirim, dan baru masuk saat diterima.`
                            : 'Permintaan stok antar cabang di seluruh cabang. Pilih cabang untuk melihat arah masuk dan keluar.'
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Perlu disetujui"
                        value={summary.to_approve}
                        hint={
                            perspective ? 'Diminta dari cabang ini.' : undefined
                        }
                    />
                    <StatCard
                        label="Perlu dikirim"
                        value={summary.to_ship}
                        hint="Sudah disetujui, stok belum keluar."
                    />
                    <StatCard
                        label="Perlu diterima"
                        value={summary.to_receive}
                        hint="Dalam perjalanan, belum dihitung di cabang mana pun."
                    />
                    {perspective && (
                        <StatCard
                            label="Menunggu cabang lain"
                            value={summary.waiting_on_others}
                            hint="Permintaan cabang ini yang belum dikirim."
                        />
                    )}
                </div>

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={stockTransfers.create()}>
                                    <Plus aria-hidden />
                                    Minta Stok
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    rows={transfers.data}
                    rowKey={(row) => row.id}
                    paginator={transfers}
                    rowLabel="transfer"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: ArrowRightLeft,
                        title: table.isFiltered
                            ? 'Tidak ada transfer yang cocok'
                            : 'Belum ada transfer stok',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat transfer lain.'
                            : 'Minta stok dari cabang lain saat gudang sendiri kekurangan.',
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
