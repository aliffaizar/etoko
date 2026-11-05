import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { PackagePlus, Plus } from 'lucide-react';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatNumber } from '@/lib/format';
import stockEntries from '@/routes/branch/stock-entries';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StockDocumentSummary,
    StockEntryRow,
    StockScopeOption,
    StockWorkspaceProps,
} from '@/types';

type StockEntriesProps = StockWorkspaceProps & {
    entries: Paginated<StockEntryRow>;
    filters: Record<string, string>;
    options: { statuses: StockScopeOption[] };
    summary: StockDocumentSummary;
    can: { create: boolean };
};

/** Query keys that name a place rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'warehouse'];

export const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    posted: 'success',
    cancelled: 'danger',
};

/**
 * Goods arriving at a warehouse (P1-E4-T5).
 *
 * The status column is the point of the screen: a **draft** has been typed but
 * has not touched the warehouse, while a **posted** note has moved the figures
 * and can no longer be changed. Everything else on the row is there to help
 * find the one somebody is looking for.
 */
export default function StockEntries({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    entries,
    options,
    summary,
    can,
}: StockEntriesProps) {
    setLayoutProps({
        breadcrumbs: [{ title: 'Barang Masuk', href: stockEntries.index() }],
    });

    const table = useTableQuery({
        only: ['entries', 'filters', 'summary', 'warehouse', 'branch'],
        scopeKeys: SCOPE_KEYS,
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari dokumen',
            placeholder: 'Cari kode, supplier, atau nomor referensi…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-48',
        },
    ];

    const columns: DataTableColumn<StockEntryRow>[] = [
        {
            key: 'code',
            header: 'Dokumen',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={stockEntries.edit(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.code}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {formatDateShort(row.date)}
                        {row.supplier_name ? ` · ${row.supplier_name}` : ''}
                        {row.reference_no ? ` · ${row.reference_no}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <div className="flex flex-col items-start gap-1">
                    <StatusBadge
                        label={row.status_label}
                        tone={STATUS_TONE[row.status] ?? 'neutral'}
                    />
                    {row.posted_by && (
                        <span className="text-xs text-muted-foreground">
                            oleh {row.posted_by}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'total_quantity',
            header: 'Unit',
            align: 'right',
            cell: (row) => (
                <div className="text-sm">
                    <p className="font-medium tabular-nums">
                        {formatNumber(row.total_quantity)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {row.item_count} baris
                    </p>
                </div>
            ),
        },
        {
            key: 'created_by',
            header: 'Dibuat oleh',
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm text-muted-foreground">
                    {row.created_by ?? '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) => (
                <Button variant="outline" size="sm" asChild>
                    <Link href={stockEntries.edit(row.id)}>
                        {row.is_editable ? 'Lanjutkan' : 'Lihat'}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={`Barang Masuk · ${warehouse.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Barang Masuk"
                    description="Dokumen dicatat sebagai draf lebih dahulu. Stok baru bertambah saat dokumen diposting, dan dokumen yang sudah diposting tidak bisa diubah."
                />

                <StockScopePicker
                    branch={branch}
                    warehouse={warehouse}
                    warehouses={warehouses}
                    branches={branches}
                    may_choose_branch={may_choose_branch}
                    onChange={table.setFilters}
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total dokumen" value={summary.total} />
                    <StatCard
                        label="Draf"
                        value={summary.draft}
                        hint="Belum menambah stok."
                    />
                    <StatCard label="Diposting" value={summary.posted} />
                    <StatCard label="Dibatalkan" value={summary.cancelled} />
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
                                <Link
                                    href={stockEntries.create({
                                        query: {
                                            warehouse: String(warehouse.id),
                                        },
                                    })}
                                >
                                    <Plus aria-hidden />
                                    Barang Masuk Baru
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    rows={entries.data}
                    rowKey={(row) => row.id}
                    paginator={entries}
                    rowLabel="dokumen"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: PackagePlus,
                        title: table.isFiltered
                            ? 'Tidak ada dokumen yang cocok'
                            : 'Belum ada barang masuk',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat dokumen lain.'
                            : `Catat kiriman pertama ke gudang ${warehouse.name}.`,
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
