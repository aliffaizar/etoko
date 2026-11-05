import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { PackageMinus, Plus } from 'lucide-react';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatNumber } from '@/lib/format';
import stockIssues from '@/routes/branch/stock-issues';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StockDocumentSummary,
    StockIssueRow,
    StockScopeOption,
    StockWorkspaceProps,
} from '@/types';

type StockIssuesProps = StockWorkspaceProps & {
    issues: Paginated<StockIssueRow>;
    filters: Record<string, string>;
    options: { statuses: StockScopeOption[]; reasons: StockScopeOption[] };
    summary: StockDocumentSummary;
    can: { create: boolean };
};

/** Query keys that name a place rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'warehouse'];

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    posted: 'success',
    cancelled: 'danger',
};

/**
 * Goods leaving a warehouse without being sold (P1-E4-T6).
 *
 * The reason sits next to the code on every row, because that is the column
 * the screen exists for: shrinkage nobody can count by cause is shrinkage
 * nobody can act on. Filtering by it answers "how much did we lose to damage
 * this month?" without a report.
 *
 * Stock sold does not appear here — it leaves through an order.
 */
export default function StockIssues({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    issues,
    options,
    summary,
    can,
}: StockIssuesProps) {
    setLayoutProps({
        breadcrumbs: [{ title: 'Barang Keluar', href: stockIssues.index() }],
    });

    const table = useTableQuery({
        only: ['issues', 'filters', 'summary', 'warehouse', 'branch'],
        scopeKeys: SCOPE_KEYS,
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari dokumen',
            placeholder: 'Cari kode atau nomor referensi…',
        },
        {
            type: 'select',
            key: 'status',
            label: 'Status',
            options: options.statuses,
            allLabel: 'Semua status',
            className: 'sm:w-44',
        },
        {
            type: 'select',
            key: 'reason',
            label: 'Alasan',
            options: options.reasons,
            allLabel: 'Semua alasan',
            className: 'sm:w-48',
        },
    ];

    const columns: DataTableColumn<StockIssueRow>[] = [
        {
            key: 'code',
            header: 'Dokumen',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={stockIssues.edit(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.code}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {formatDateShort(row.date)}
                        {row.reference_no ? ` · ${row.reference_no}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Alasan',
            cell: (row) => <span className="text-sm">{row.reason_label}</span>,
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
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) => (
                <Button variant="outline" size="sm" asChild>
                    <Link href={stockIssues.edit(row.id)}>
                        {row.is_editable ? 'Lanjutkan' : 'Lihat'}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={`Barang Keluar · ${warehouse.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Barang Keluar"
                    description="Untuk barang rusak, hilang, dipakai internal, atau jadi sampel. Barang yang terjual keluar lewat pesanan, bukan lewat dokumen ini."
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
                        hint="Belum mengurangi stok."
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
                                    href={stockIssues.create({
                                        query: {
                                            warehouse: String(warehouse.id),
                                        },
                                    })}
                                >
                                    <Plus aria-hidden />
                                    Barang Keluar Baru
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    rows={issues.data}
                    rowKey={(row) => row.id}
                    paginator={issues}
                    rowLabel="dokumen"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: PackageMinus,
                        title: table.isFiltered
                            ? 'Tidak ada dokumen yang cocok'
                            : 'Belum ada barang keluar',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat dokumen lain.'
                            : 'Bagus — belum ada barang yang keluar tanpa terjual.',
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
