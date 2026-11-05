import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Check, Package, X } from 'lucide-react';
import { useState } from 'react';
import BranchProductController from '@/actions/App/Http/Controllers/Admin/BranchProductController';
import Heading from '@/components/heading';
import { DataTable } from '@/components/panel/data-table';
import { FilterBar } from '@/components/panel/filter-bar';
import { StatCard } from '@/components/panel/stat-card';
import { StatusBadge } from '@/components/panel/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTableQuery } from '@/hooks/use-table-query';
import { formatRupiah } from '@/lib/format';
import {
    edit as branchEdit,
    index as branchesIndex,
} from '@/routes/admin/branches';
import branchProducts from '@/routes/admin/branches/products';
import type {
    BranchCatalogBranch,
    BranchCatalogOptions,
    BranchProductRow,
    BranchProductSummary,
    DataTableColumn,
    FilterField,
    Paginated,
} from '@/types';

type BranchProductsProps = {
    branch: BranchCatalogBranch;
    products: Paginated<BranchProductRow>;
    filters: Record<string, string>;
    options: BranchCatalogOptions;
    summary: BranchProductSummary;
    can: { manage: boolean };
    limits: { max_products: number };
};

const AVAILABILITY_OPTIONS = [
    { value: 'available', label: 'Dijual di cabang ini' },
    { value: 'unavailable', label: 'Tidak dijual' },
];

/**
 * Which products one branch sells (P1-E3-T9).
 *
 * Only the exceptions are stored, so a row with no setting of its own reads
 * "mengikuti default" rather than looking like a decision someone made. That
 * distinction is the whole point of the screen: "which products has this
 * branch actually switched off?" should be answerable at a glance.
 */
export default function BranchProducts({
    branch,
    products,
    options,
    summary,
    can,
    limits,
}: BranchProductsProps) {
    const [selectedIds, setSelectedIds] = useState<(string | number)[]>([]);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Cabang', href: branchesIndex() },
            { title: branch.name, href: branchEdit(branch.id) },
            {
                title: 'Ketersediaan Produk',
                href: branchProducts.index(branch.id),
            },
        ],
    });

    const table = useTableQuery({
        only: ['products', 'filters', 'summary'],
        defaultSort: 'name',
        defaultDirection: 'asc',
    });

    const filterFields: FilterField[] = [
        {
            type: 'search',
            key: 'search',
            label: 'Cari produk',
            placeholder: 'Cari nama, slug, atau SKU…',
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
            key: 'availability',
            label: 'Ketersediaan',
            options: AVAILABILITY_OPTIONS,
            allLabel: 'Semua',
            className: 'sm:w-52',
        },
    ];

    const applyAvailability = (
        productIds: (string | number)[],
        isAvailable: boolean,
    ): void => {
        router.put(
            BranchProductController.update(branch.id).url,
            { product_ids: productIds, is_available: isAvailable },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds([]),
            },
        );
    };

    const columns: DataTableColumn<BranchProductRow>[] = [
        {
            key: 'name',
            header: 'Produk',
            sortKey: 'name',
            cell: (product) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{product.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="font-mono">{product.sku ?? '—'}</span>
                        {` · ${product.category_name}`}
                    </p>
                </div>
            ),
        },
        {
            key: 'base_price',
            header: 'Harga katalog',
            align: 'right',
            sortKey: 'base_price',
            hideOnMobile: true,
            cell: (product) => (
                <span className="text-sm tabular-nums">
                    {formatRupiah(product.base_price)}
                </span>
            ),
        },
        {
            key: 'catalogue_state',
            header: 'Di katalog',
            hideOnMobile: true,
            cell: (product) => (
                <StatusBadge
                    label={product.is_published ? 'Terbit' : 'Belum terbit'}
                    tone={product.is_published ? 'success' : 'neutral'}
                />
            ),
        },
        {
            key: 'is_available',
            header: 'Dijual di cabang',
            cell: (product) => (
                <div className="flex items-center gap-2">
                    <Checkbox
                        checked={product.is_available}
                        disabled={!can.manage}
                        aria-label={`Dijual di cabang ${branch.name}: ${product.name}`}
                        onCheckedChange={(checked) =>
                            applyAvailability([product.id], checked === true)
                        }
                    />
                    <div className="min-w-0">
                        <p className="text-sm">
                            {product.is_available ? 'Ya' : 'Tidak'}
                        </p>
                        {!product.has_setting && (
                            <p className="text-xs text-muted-foreground">
                                mengikuti default
                            </p>
                        )}
                        {product.notes && (
                            <p className="truncate text-xs text-muted-foreground">
                                {product.notes}
                            </p>
                        )}
                    </div>
                </div>
            ),
        },
    ];

    const bulkBar = can.manage && selectedIds.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3">
            <p className="text-sm">
                <strong className="tabular-nums">{selectedIds.length}</strong>{' '}
                produk dipilih
                {selectedIds.length > limits.max_products && (
                    <span className="text-destructive">
                        {' '}
                        — maksimal {limits.max_products} produk sekali ubah
                    </span>
                )}
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
                    variant="outline"
                    size="sm"
                    disabled={selectedIds.length > limits.max_products}
                    onClick={() => applyAvailability(selectedIds, true)}
                >
                    <Check aria-hidden />
                    Tandai dijual
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    disabled={selectedIds.length > limits.max_products}
                    onClick={() => applyAvailability(selectedIds, false)}
                >
                    <X aria-hidden />
                    Tandai tidak dijual
                </Button>
            </div>
        </div>
    );

    return (
        <>
            <Head title={`Ketersediaan Produk · ${branch.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Ketersediaan Produk · ${branch.name}`}
                        description="Katalog dikelola pusat. Di sini ditentukan produk mana yang dijual cabang ini — produk tanpa pengaturan otomatis dijual."
                    />
                    <Button variant="outline" asChild>
                        <Link href={branchEdit(branch.id)}>
                            Kembali ke cabang
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Produk di katalog" value={summary.total} />
                    <StatCard
                        label="Dijual di cabang ini"
                        value={summary.available}
                    />
                    <StatCard
                        label="Tidak dijual"
                        value={summary.unavailable}
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
                    rows={products.data}
                    rowKey={(product) => product.id}
                    paginator={products}
                    rowLabel="produk"
                    sort={table.sort}
                    onSortChange={table.toggleSort}
                    goToPage={table.goToPage}
                    pageUrl={table.pageUrl}
                    isLoading={table.isLoading}
                    selection={
                        can.manage
                            ? {
                                  selectedKeys: selectedIds,
                                  onChange: setSelectedIds,
                                  rowLabel: (product) =>
                                      `Pilih ${product.name}`,
                              }
                            : undefined
                    }
                    empty={{
                        icon: Package,
                        title: table.isFiltered
                            ? 'Tidak ada produk yang cocok'
                            : 'Belum ada produk di katalog',
                        description: table.isFiltered
                            ? 'Ubah atau hapus filter untuk melihat produk lain.'
                            : 'Tambahkan produk ke katalog pusat lebih dahulu.',
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
