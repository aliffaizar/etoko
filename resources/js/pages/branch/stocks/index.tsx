import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Boxes, History, Settings2 } from 'lucide-react';
import { useState } from 'react';
import StockController from '@/actions/App/Http/Controllers/Branch/StockController';
import { StockScopePicker } from '@/components/branch/stock-scope-picker';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
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
import { formatNumber } from '@/lib/format';
import stocks from '@/routes/branch/stocks';
import type {
    DataTableColumn,
    FilterField,
    Paginated,
    StockRow,
    StockSummary,
    StockWorkspaceProps,
} from '@/types';

type BranchStocksProps = StockWorkspaceProps & {
    variants: Paginated<StockRow>;
    filters: Record<string, string>;
    options: {
        categories: Array<{ value: number; label: string; depth: number }>;
    };
    summary: StockSummary;
    can: { adjust: boolean };
};

const STATE_OPTIONS = [
    { value: 'low', label: 'Di bawah minimum' },
    { value: 'out', label: 'Stok habis' },
    { value: 'in_stock', label: 'Ada stok' },
];

/** Query keys that name a place rather than narrowing the list. */
const SCOPE_KEYS = ['branch', 'warehouse'];

/**
 * What a branch holds, per warehouse (P1-E4-T3, T4).
 *
 * Four figures per row, and they say different things: **on-hand** is what is
 * on the shelf, **ditahan** is what checkout has already promised a buyer,
 * **tersedia** is the difference and the only one a sale may draw on (BR-6),
 * and **minimum** is the line below which somebody wants to be told.
 *
 * Every variant in the catalogue appears, including the ones this warehouse
 * has never stocked — that is exactly the set somebody comes here to set a
 * minimum for, or to receive a first delivery of.
 */
export default function BranchStocks({
    branch,
    warehouse,
    warehouses,
    branches,
    may_choose_branch,
    variants,
    options,
    summary,
    can,
}: BranchStocksProps) {
    const [adjusting, setAdjusting] = useState<StockRow | null>(null);
    const [isSettingThresholds, setIsSettingThresholds] = useState(false);
    const [selectedIds, setSelectedIds] = useState<(string | number)[]>([]);

    setLayoutProps({
        breadcrumbs: [{ title: 'Stok', href: stocks.index() }],
    });

    const table = useTableQuery({
        only: [
            'variants',
            'filters',
            'summary',
            'warehouse',
            'warehouses',
            'branch',
            'can',
        ],
        defaultSort: 'sku',
        defaultDirection: 'asc',
        scopeKeys: SCOPE_KEYS,
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari varian',
            placeholder: 'Cari SKU, barcode, atau nama produk…',
        },
        {
            type: 'select',
            key: 'category',
            label: 'Kategori',
            options: options.categories.map((category) => ({
                value: String(category.value),
                label: `${'— '.repeat(Math.max(category.depth - 1, 0))}${category.label}`,
            })),
            allLabel: 'Semua kategori',
            className: 'sm:w-56',
        },
        {
            type: 'select',
            key: 'state',
            label: 'Kondisi stok',
            options: STATE_OPTIONS,
            allLabel: 'Semua kondisi',
            className: 'sm:w-52',
        },
    ];

    const columns: DataTableColumn<StockRow>[] = [
        {
            key: 'sku',
            header: 'Varian',
            sortKey: 'sku',
            cell: (row) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{row.product_name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="font-mono">{row.sku}</span>
                        {row.name ? ` · ${row.name}` : ''}
                        {` · ${row.category_name}`}
                        {row.shelf_location
                            ? ` · rak ${row.shelf_location}`
                            : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'on_hand',
            header: 'Di gudang',
            align: 'right',
            sortKey: 'stock_quantity',
            cell: (row) => (
                <span className="text-sm font-medium tabular-nums">
                    {formatNumber(row.on_hand)}
                </span>
            ),
        },
        {
            key: 'reserved',
            header: 'Ditahan',
            align: 'right',
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm text-muted-foreground tabular-nums">
                    {formatNumber(row.reserved)}
                </span>
            ),
        },
        {
            key: 'available',
            header: 'Tersedia',
            align: 'right',
            sortKey: 'stock_available',
            cell: (row) => (
                <span className="text-sm tabular-nums">
                    {formatNumber(row.available)}
                </span>
            ),
        },
        {
            key: 'min_quantity',
            header: 'Minimum',
            align: 'right',
            hideOnMobile: true,
            cell: (row) =>
                row.min_quantity === 0 ? (
                    <span className="text-xs text-muted-foreground">
                        belum diatur
                    </span>
                ) : (
                    <span className="text-sm tabular-nums">
                        {formatNumber(row.min_quantity)}
                    </span>
                ),
        },
        {
            key: 'state',
            header: 'Kondisi',
            cell: (row) =>
                row.is_low ? (
                    <StatusBadge label="Di bawah minimum" tone="danger" />
                ) : row.on_hand === 0 ? (
                    <StatusBadge label="Habis" tone="neutral" />
                ) : (
                    <StatusBadge label="Aman" tone="success" />
                ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (row) => (
                <div className="flex flex-wrap items-center justify-end gap-1">
                    {can.adjust && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setAdjusting(row)}
                        >
                            Sesuaikan
                        </Button>
                    )}
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={stocks.movements.url([row.id], {
                                query: {
                                    branch: may_choose_branch
                                        ? String(branch.id)
                                        : undefined,
                                    warehouse: String(warehouse.id),
                                },
                            })}
                        >
                            <History aria-hidden />
                            Riwayat
                        </Link>
                    </Button>
                </div>
            ),
        },
    ];

    const bulkBar = can.adjust && selectedIds.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3">
            <p className="text-sm">
                <strong className="tabular-nums">{selectedIds.length}</strong>{' '}
                varian dipilih
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSelectedIds([])}
                >
                    Bersihkan pilihan
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setIsSettingThresholds(true)}
                >
                    <Settings2 aria-hidden />
                    Atur ambang stok
                </Button>
            </div>
        </div>
    );

    return (
        <>
            <Head title={`Stok · ${warehouse.name} · ${branch.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Stok Cabang"
                    description="Tersedia = di gudang − ditahan checkout. Hanya stok tersedia yang boleh dijual."
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
                    <StatCard
                        label="Unit di gudang"
                        value={summary.on_hand}
                        hint={`${formatNumber(summary.reserved)} unit ditahan checkout`}
                    />
                    <StatCard
                        label="Varian bergudang"
                        value={summary.stocked}
                        hint={`dari ${formatNumber(summary.variants)} varian di katalog`}
                    />
                    <StatCard
                        label="Di bawah minimum"
                        value={summary.low}
                        hint="Varian yang perlu dipesan ulang."
                    />
                    <StatCard
                        label="Varian tanpa stok"
                        value={summary.variants - summary.stocked}
                    />
                </div>

                <FilterBar
                    fields={filterFields}
                    values={table.values}
                    onChange={table.setFilters}
                    onReset={table.reset}
                    isFiltered={table.isFiltered}
                />

                {bulkBar}

                <DataTable
                    columns={columns}
                    rows={variants.data}
                    rowKey={(row) => row.id}
                    paginator={variants}
                    rowLabel="varian"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    selection={
                        can.adjust
                            ? {
                                  selectedKeys: selectedIds,
                                  onChange: setSelectedIds,
                                  rowLabel: (row) => `Pilih ${row.sku}`,
                              }
                            : undefined
                    }
                    rowClassName={(row) =>
                        row.is_active ? undefined : 'opacity-60'
                    }
                    empty={{
                        icon: Boxes,
                        title: table.isFiltered
                            ? 'Tidak ada varian yang cocok'
                            : 'Belum ada varian di katalog',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat varian lain.'
                            : 'Katalog produk diatur dari pusat; stok baru bisa dicatat setelah ada varian.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>

            <AdjustStockDialog
                // Remount per variant, so the form's defaults belong to the row
                // that was clicked.
                key={adjusting?.id ?? 'adjust'}
                warehouseId={warehouse.id}
                warehouseName={warehouse.name}
                row={adjusting}
                onClose={() => setAdjusting(null)}
            />

            <ThresholdsDialog
                key={`thresholds-${selectedIds.length}`}
                open={isSettingThresholds}
                warehouseId={warehouse.id}
                warehouseName={warehouse.name}
                variantIds={selectedIds}
                onClose={() => setIsSettingThresholds(false)}
                onSaved={() => {
                    setIsSettingThresholds(false);
                    setSelectedIds([]);
                }}
            />
        </>
    );
}

type AdjustFormData = {
    warehouse_id: string;
    quantity_change: string;
    notes: string;
};

/**
 * A by-hand correction, entered as a **relative** change.
 *
 * Deliberately not "set the new total": a relative number is what the person
 * actually knows ("two more than the paperwork said", "one broken"), and it is
 * what the ledger records. Counting a shelf and entering the total is a stock
 * opname, which works out the difference itself (P1-E4-T7).
 */
function AdjustStockDialog({
    warehouseId,
    warehouseName,
    row,
    onClose,
}: {
    warehouseId: number;
    warehouseName: string;
    row: StockRow | null;
    onClose: () => void;
}) {
    if (!row) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <Form<AdjustFormData>
                    {...StockController.adjust.form(row.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Sesuaikan stok {row.sku}
                                </DialogTitle>
                                <DialogDescription>
                                    {row.product_name}
                                    {row.name ? ` · ${row.name}` : ''} di{' '}
                                    {warehouseName} — sekarang{' '}
                                    {formatNumber(row.on_hand)} unit. Isi angka
                                    positif untuk menambah, negatif untuk
                                    mengurangi.
                                </DialogDescription>
                            </DialogHeader>

                            <input
                                type="hidden"
                                name="warehouse_id"
                                value={warehouseId}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="quantity_change">
                                    Penyesuaian (unit)
                                </Label>
                                <Input
                                    id="quantity_change"
                                    name="quantity_change"
                                    type="number"
                                    inputMode="numeric"
                                    required
                                    placeholder="contoh: -2"
                                />
                                <InputError message={errors.quantity_change} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="adjust_notes">
                                    Alasan penyesuaian
                                </Label>
                                <Textarea
                                    id="adjust_notes"
                                    name="notes"
                                    required
                                    rows={3}
                                    maxLength={255}
                                    placeholder="contoh: satu dus rusak kena air"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Alasan wajib diisi supaya perubahan ini bisa
                                    dijelaskan di riwayat stok.
                                </p>
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
                                    Simpan Penyesuaian
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

type ThresholdsFormData = {
    warehouse_id: string;
    min_quantity: string;
    max_quantity: string;
    shelf_location: string;
};

/**
 * Reorder thresholds for every selected variant at once (P1-E4-T4).
 *
 * Nothing here moves stock, so nothing is written to the ledger: a minimum is
 * a setting that decides when somebody gets told, not a figure on a shelf.
 */
function ThresholdsDialog({
    open,
    warehouseId,
    warehouseName,
    variantIds,
    onClose,
    onSaved,
}: {
    open: boolean;
    warehouseId: number;
    warehouseName: string;
    variantIds: (string | number)[];
    onClose: () => void;
    onSaved: () => void;
}) {
    if (!open) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <Form<ThresholdsFormData>
                    {...StockController.updateThresholds.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={onSaved}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Atur ambang stok {variantIds.length} varian
                                </DialogTitle>
                                <DialogDescription>
                                    Berlaku di gudang {warehouseName}. Stok di
                                    bawah minimum akan memicu peringatan stok
                                    rendah.
                                </DialogDescription>
                            </DialogHeader>

                            <input
                                type="hidden"
                                name="warehouse_id"
                                value={warehouseId}
                            />
                            {variantIds.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="variant_ids[]"
                                    value={String(id)}
                                />
                            ))}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="min_quantity">
                                        Stok minimum
                                    </Label>
                                    <Input
                                        id="min_quantity"
                                        name="min_quantity"
                                        type="number"
                                        min={0}
                                        required
                                        defaultValue={0}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Isi 0 untuk mematikan peringatan.
                                    </p>
                                    <InputError message={errors.min_quantity} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="max_quantity">
                                        Stok maksimum{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="max_quantity"
                                        name="max_quantity"
                                        type="number"
                                        min={0}
                                    />
                                    <InputError message={errors.max_quantity} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="shelf_location">
                                    Lokasi rak{' '}
                                    <span className="text-muted-foreground">
                                        (opsional)
                                    </span>
                                </Label>
                                <Input
                                    id="shelf_location"
                                    name="shelf_location"
                                    maxLength={255}
                                    placeholder="contoh: A-03-02"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Dikosongkan berarti lokasi rak varian
                                    terpilih dihapus.
                                </p>
                                <InputError message={errors.shelf_location} />
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
                                    Simpan Ambang
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
