import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Tags } from 'lucide-react';
import { useState } from 'react';
import BranchProductPriceController from '@/actions/App/Http/Controllers/Admin/BranchProductPriceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge, type StatusTone } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useTableQuery } from '@/hooks/use-table-query';
import { formatRupiah } from '@/lib/format';
import {
    edit as branchEdit,
    index as branchesIndex,
} from '@/routes/admin/branches';
import branchPrices from '@/routes/admin/branches/prices';
import type {
    BranchCatalogBranch,
    BranchCatalogOptions,
    BranchPriceRow,
    BranchPriceState,
    BranchPriceSummary,
    DataTableColumn,
    FilterField,
    Paginated,
} from '@/types';

type BranchPricesProps = {
    branch: BranchCatalogBranch;
    variants: Paginated<BranchPriceRow>;
    filters: Record<string, string>;
    options: BranchCatalogOptions;
    summary: BranchPriceSummary;
    can: { manage: boolean };
};

const PRICING_OPTIONS = [
    { value: 'custom', label: 'Punya harga khusus' },
    { value: 'catalogue', label: 'Memakai harga katalog' },
];

const STATE_TONE: Record<BranchPriceState, StatusTone> = {
    active: 'success',
    scheduled: 'info',
    expired: 'neutral',
    inactive: 'danger',
};

const STATE_LABEL: Record<BranchPriceState, string> = {
    active: 'Berlaku',
    scheduled: 'Terjadwal',
    expired: 'Kedaluwarsa',
    inactive: 'Nonaktif',
};

/**
 * What one branch charges (P1-E3-T10).
 *
 * Every row shows the price actually in force together with where it came
 * from, because "Rp 250.000 because this branch says so" and "Rp 250.000
 * because the catalogue says so" look identical otherwise — and only the first
 * survives a change to the catalogue price (BR-8).
 */
export default function BranchPrices({
    branch,
    variants,
    options,
    summary,
    can,
}: BranchPricesProps) {
    const [editing, setEditing] = useState<BranchPriceRow | null>(null);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Cabang', href: branchesIndex() },
            { title: branch.name, href: branchEdit(branch.id) },
            { title: 'Harga Khusus', href: branchPrices.index(branch.id) },
        ],
    });

    const table = useTableQuery({
        only: ['variants', 'filters', 'summary'],
        defaultSort: 'sku',
        defaultDirection: 'asc',
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
            key: 'pricing',
            label: 'Harga',
            options: PRICING_OPTIONS,
            allLabel: 'Semua',
            className: 'sm:w-56',
        },
    ];

    const columns: DataTableColumn<BranchPriceRow>[] = [
        {
            key: 'sku',
            header: 'Varian',
            sortKey: 'sku',
            cell: (variant) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {variant.product_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="font-mono">{variant.sku}</span>
                        {variant.name ? ` · ${variant.name}` : ''}
                        {` · ${variant.category_name}`}
                    </p>
                </div>
            ),
        },
        {
            key: 'catalogue_price',
            header: 'Harga katalog',
            align: 'right',
            hideOnMobile: true,
            cell: (variant) => (
                <span className="text-sm text-muted-foreground tabular-nums">
                    {formatRupiah(variant.catalogue_price)}
                </span>
            ),
        },
        {
            key: 'effective_price',
            header: 'Harga berlaku',
            align: 'right',
            cell: (variant) => (
                <div className="text-sm">
                    <p className="font-medium tabular-nums">
                        {formatRupiah(variant.effective_price)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {variant.effective_source_label}
                    </p>
                </div>
            ),
        },
        {
            key: 'branch_price',
            header: 'Harga khusus',
            cell: (variant) =>
                variant.branch_price === null ? (
                    <span className="text-xs text-muted-foreground">
                        Belum diatur
                    </span>
                ) : (
                    <div className="flex flex-col items-start gap-1">
                        <span className="text-sm tabular-nums">
                            {formatRupiah(variant.branch_price.price)}
                        </span>
                        <StatusBadge
                            label={STATE_LABEL[variant.branch_price.state]}
                            tone={STATE_TONE[variant.branch_price.state]}
                        />
                    </div>
                ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            srOnlyHeader: true,
            align: 'right',
            cell: (variant) =>
                can.manage ? (
                    <div className="flex flex-wrap items-center justify-end gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(variant)}
                        >
                            {variant.branch_price === null
                                ? 'Atur harga'
                                : 'Ubah'}
                        </Button>
                        {variant.branch_price !== null && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() =>
                                    router.delete(
                                        BranchProductPriceController.destroy([
                                            branch.id,
                                            variant.id,
                                        ]).url,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Hapus
                            </Button>
                        )}
                    </div>
                ) : null,
        },
    ];

    return (
        <>
            <Head title={`Harga Khusus · ${branch.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Harga Khusus · ${branch.name}`}
                        description="Harga diatur per varian, karena keranjang dan pesanan selalu menunjuk varian. Varian tanpa harga khusus memakai harga katalog."
                    />
                    <Button variant="outline" asChild>
                        <Link href={branchEdit(branch.id)}>
                            Kembali ke cabang
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Varian di katalog" value={summary.total} />
                    <StatCard
                        label="Punya harga khusus"
                        value={summary.custom}
                    />
                    <StatCard
                        label="Sedang berlaku"
                        value={summary.in_force}
                        hint="Sisanya nonaktif, terjadwal, atau kedaluwarsa."
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
                    rows={variants.data}
                    rowKey={(variant) => variant.id}
                    paginator={variants}
                    rowLabel="varian"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    rowClassName={(variant) =>
                        variant.is_active ? undefined : 'opacity-60'
                    }
                    empty={{
                        icon: Tags,
                        title: table.isFiltered
                            ? 'Tidak ada varian yang cocok'
                            : 'Belum ada varian di katalog',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat varian lain.'
                            : 'Tambahkan produk ke katalog pusat lebih dahulu.',
                        action: table.isFiltered ? (
                            <Button variant="outline" onClick={table.reset}>
                                Reset filter
                            </Button>
                        ) : undefined,
                    }}
                />
            </div>

            <BranchPriceDialog
                // Remount for another variant, so the dialog's defaults are the
                // ones of the row that was clicked.
                key={editing?.id ?? 'edit'}
                branch={branch}
                variant={editing}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

type BranchPriceFormData = {
    price: string;
    compare_at_price: string;
    starts_at: string;
    ends_at: string;
    is_active: string;
};

function BranchPriceDialog({
    branch,
    variant,
    onClose,
}: {
    branch: BranchCatalogBranch;
    variant: BranchPriceRow | null;
    onClose: () => void;
}) {
    const [isActive, setIsActive] = useState(
        variant?.branch_price?.is_active ?? true,
    );

    if (!variant) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <Form<BranchPriceFormData>
                    {...BranchProductPriceController.store.form([
                        branch.id,
                        variant.id,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Harga khusus {variant.sku}
                                </DialogTitle>
                                <DialogDescription>
                                    {variant.product_name}
                                    {variant.name ? ` · ${variant.name}` : ''} ·
                                    harga katalog{' '}
                                    {formatRupiah(variant.catalogue_price)}.
                                    Hapus harga khusus bila cabang ini kembali
                                    memakai harga katalog.
                                </DialogDescription>
                            </DialogHeader>

                            <input
                                type="hidden"
                                name="is_active"
                                value={isActive ? '1' : '0'}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="branch_price">
                                        Harga cabang (Rp)
                                    </Label>
                                    <Input
                                        id="branch_price"
                                        name="price"
                                        type="number"
                                        min={0}
                                        required
                                        defaultValue={
                                            variant.branch_price?.price ?? ''
                                        }
                                        placeholder={String(
                                            variant.catalogue_price,
                                        )}
                                    />
                                    <InputError message={errors.price} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="branch_compare_at_price">
                                        Harga sebelum diskon{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="branch_compare_at_price"
                                        name="compare_at_price"
                                        type="number"
                                        min={0}
                                        defaultValue={
                                            variant.branch_price
                                                ?.compare_at_price ?? ''
                                        }
                                    />
                                    <InputError
                                        message={errors.compare_at_price}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="branch_starts_at">
                                        Mulai berlaku{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="branch_starts_at"
                                        name="starts_at"
                                        type="datetime-local"
                                        defaultValue={
                                            variant.branch_price?.starts_at ??
                                            ''
                                        }
                                    />
                                    <InputError message={errors.starts_at} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="branch_ends_at">
                                        Berakhir{' '}
                                        <span className="text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="branch_ends_at"
                                        name="ends_at"
                                        type="datetime-local"
                                        defaultValue={
                                            variant.branch_price?.ends_at ?? ''
                                        }
                                    />
                                    <InputError message={errors.ends_at} />
                                </div>
                            </div>

                            <div className="flex items-start gap-3 rounded-lg border p-3">
                                <Checkbox
                                    id="branch_price_is_active"
                                    checked={isActive}
                                    onCheckedChange={(checked) =>
                                        setIsActive(checked === true)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="branch_price_is_active">
                                        Harga aktif
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Harga nonaktif tetap tersimpan tetapi
                                        tidak dipakai; cabang kembali memakai
                                        harga katalog.
                                    </p>
                                    <InputError message={errors.is_active} />
                                </div>
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
                                    Simpan Harga
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
