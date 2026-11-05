import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { ClipboardList, Plus } from 'lucide-react';
import { useState } from 'react';
import StockOpnameController from '@/actions/App/Http/Controllers/Branch/StockOpnameController';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatDateShort, formatNumber } from '@/lib/format';
import stockOpnames from '@/routes/branch/stock-opnames';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StockCategoryOption,
    StockOpnameRow,
    StockScopeOption,
    StockWorkspaceProps,
} from '@/types';

type StockOpnamesProps = StockWorkspaceProps & {
    opnames: Paginated<StockOpnameRow>;
    filters: Record<string, string>;
    options: {
        statuses: StockScopeOption[];
        categories: StockCategoryOption[];
    };
    can: { create: boolean };
};

/** Query keys that name a place rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'warehouse'];

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    in_progress: 'warning',
    completed: 'success',
    cancelled: 'danger',
};

/**
 * Physical stock counts (P1-E4-T7).
 *
 * The progress column is what the listing is for: a count takes hours, so
 * "12 of 80 counted" is the thing somebody needs to see before deciding
 * whether to carry on or close the session.
 */
export default function StockOpnames({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    opnames,
    options,
    can,
}: StockOpnamesProps) {
    const [isOpening, setIsOpening] = useState(false);

    setLayoutProps({
        breadcrumbs: [{ title: 'Stok Opname', href: stockOpnames.index() }],
    });

    const table = useTableQuery({
        only: ['opnames', 'filters', 'warehouse', 'branch'],
        scopeKeys: SCOPE_KEYS,
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari sesi',
            placeholder: 'Cari kode sesi…',
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

    const columns: DataTableColumn<StockOpnameRow>[] = [
        {
            key: 'code',
            header: 'Sesi',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={stockOpnames.show(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.code}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        dihitung {formatDateShort(row.counted_at)}
                        {row.created_by ? ` · ${row.created_by}` : ''}
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
                    {row.approved_by && (
                        <span className="text-xs text-muted-foreground">
                            disetujui {row.approved_by}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'progress',
            header: 'Kemajuan',
            align: 'right',
            cell: (row) => (
                <div className="text-sm">
                    <p className="font-medium tabular-nums">
                        {formatNumber(row.counted_count)} /{' '}
                        {formatNumber(row.item_count)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        baris sudah dihitung
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
                    <Link href={stockOpnames.show(row.id)}>
                        {row.is_editable ? 'Lanjutkan hitung' : 'Lihat hasil'}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={`Stok Opname · ${warehouse.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Stok Opname"
                    description="Hitung fisik per gudang. Sesi menyimpan angka sistem saat dibuka, sehingga selisihnya tetap bisa dibaca setelah sesi ditutup."
                />

                <StockScopePicker
                    branch={branch}
                    warehouse={warehouse}
                    warehouses={warehouses}
                    branches={branches}
                    may_choose_branch={may_choose_branch}
                    onChange={table.setFilters}
                />

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                    actions={
                        can.create ? (
                            <Button onClick={() => setIsOpening(true)}>
                                <Plus aria-hidden />
                                Buka Sesi Opname
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    rows={opnames.data}
                    rowKey={(row) => row.id}
                    paginator={opnames}
                    rowLabel="sesi"
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    empty={{
                        icon: ClipboardList,
                        title: table.isFiltered
                            ? 'Tidak ada sesi yang cocok'
                            : 'Belum ada sesi opname',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat sesi lain.'
                            : `Buka sesi untuk menghitung isi gudang ${warehouse.name}.`,
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>

            <OpenOpnameDialog
                open={isOpening}
                warehouseId={warehouse.id}
                warehouseName={warehouse.name}
                categories={options.categories}
                onClose={() => setIsOpening(false)}
            />
        </>
    );
}

type OpenOpnameFormData = {
    warehouse_id: string;
    counted_at: string;
    category_id: string;
    notes: string;
};

/**
 * Opening a session: which warehouse, which day, and how much of it.
 *
 * The scope matters because counting everything at once is rare. Leaving the
 * category empty counts everything the warehouse holds; picking one counts
 * that aisle and its subcategories.
 */
function OpenOpnameDialog({
    open,
    warehouseId,
    warehouseName,
    categories,
    onClose,
}: {
    open: boolean;
    warehouseId: number;
    warehouseName: string;
    categories: StockCategoryOption[];
    onClose: () => void;
}) {
    if (!open) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<OpenOpnameFormData>
                    {...StockOpnameController.store.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Buka sesi opname</DialogTitle>
                                <DialogDescription>
                                    Sesi dibuka untuk gudang {warehouseName}.
                                    Angka sistem disimpan sekarang, jadi
                                    penjualan yang terjadi selama penghitungan
                                    tidak ikut terhapus saat sesi ditutup.
                                </DialogDescription>
                            </DialogHeader>

                            <input
                                type="hidden"
                                name="warehouse_id"
                                value={warehouseId}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="counted_at">
                                    Tanggal hitung
                                </Label>
                                <Input
                                    id="counted_at"
                                    name="counted_at"
                                    type="date"
                                    required
                                    defaultValue={new Date()
                                        .toISOString()
                                        .slice(0, 10)}
                                />
                                <InputError message={errors.counted_at} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="category_id">
                                    Lingkup opname
                                </Label>
                                <select
                                    id="category_id"
                                    name="category_id"
                                    className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                    defaultValue=""
                                >
                                    <option value="">
                                        Semua varian yang ada stoknya di gudang
                                        ini
                                    </option>
                                    {categories.map((category) => (
                                        <option
                                            key={category.value}
                                            value={category.value}
                                        >
                                            {'— '.repeat(
                                                Math.max(category.depth - 1, 0),
                                            )}
                                            {category.label}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    Memilih kategori ikut menyertakan
                                    subkategorinya.
                                </p>
                                <InputError message={errors.category_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="opname_notes">
                                    Catatan{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="opname_notes"
                                    name="notes"
                                    rows={2}
                                    maxLength={1000}
                                />
                                <InputError message={errors.notes} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Buka Sesi
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
